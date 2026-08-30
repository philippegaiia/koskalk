<?php

use App\Actions\Inventory\SaveMaterialBuffer;
use App\Enums\OwnerType;
use App\Models\Ingredient;
use App\Models\PackagingItem;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMaterialSetting;
use App\Services\ProductionBenchAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('stores one ingredient buffer per workspace in canonical units', function (): void {
    $workspace = Workspace::factory()->create();
    $ingredient = Ingredient::factory()->create();

    $setting = WorkspaceMaterialSetting::factory()
        ->for($workspace)
        ->for($ingredient)
        ->create([
            'buffer_quantity' => '1250.000000000',
        ]);

    expect($setting->buffer_quantity)->toBe('1250.000000000')
        ->and($setting->packaging_item_id)->toBeNull();
});

it('stores packaging buffers for the owning workspace', function (): void {
    $workspace = Workspace::factory()->create();
    $packaging = PackagingItem::factory()->for($workspace)->create();

    $setting = WorkspaceMaterialSetting::factory()
        ->forPackagingItem($packaging)
        ->create();

    expect($setting->ingredient_id)->toBeNull()
        ->and($setting->packaging_item_id)->toBe($packaging->id);
});

it('rejects settings with zero or two subjects', function (array $subjectColumns): void {
    $workspace = Workspace::factory()->create();
    $ingredient = Ingredient::factory()->create();
    $packaging = PackagingItem::factory()->for($workspace)->create();

    expect(fn () => DB::table('workspace_material_settings')->insert([
        'workspace_id' => $workspace->id,
        'ingredient_id' => $subjectColumns['ingredient'] ? $ingredient->id : null,
        'packaging_item_id' => $subjectColumns['packaging'] ? $packaging->id : null,
        'buffer_quantity' => '1.000000000',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
})->with([
    'no subject' => [['ingredient' => false, 'packaging' => false]],
    'two subjects' => [['ingredient' => true, 'packaging' => true]],
]);

it('rejects duplicate workspace material settings', function (): void {
    $setting = WorkspaceMaterialSetting::factory()->create();

    expect(fn () => WorkspaceMaterialSetting::factory()->create([
        'workspace_id' => $setting->workspace_id,
        'ingredient_id' => $setting->ingredient_id,
    ]))->toThrow(QueryException::class);
});

it('creates updates and clears an ingredient buffer', function (): void {
    $fixture = materialBufferFixture();
    $action = app(SaveMaterialBuffer::class);

    $action->handle($fixture['user'], $fixture['workspace'], $fixture['ingredient'], '1250.000000000');
    expect(WorkspaceMaterialSetting::query()->sole()->buffer_quantity)->toBe('1250.000000000');

    $action->handle($fixture['user'], $fixture['workspace'], $fixture['ingredient'], '900.000000000');
    expect(WorkspaceMaterialSetting::query()->sole()->buffer_quantity)->toBe('900.000000000');

    $action->handle($fixture['user'], $fixture['workspace'], $fixture['ingredient'], null);
    expect(WorkspaceMaterialSetting::query()->count())->toBe(0);
});

it('normalizes localized buffer quantities to nine decimal places', function (): void {
    $fixture = materialBufferFixture();

    app(SaveMaterialBuffer::class)->handle(
        $fixture['user'],
        $fixture['workspace'],
        $fixture['ingredient'],
        '1 250,5',
    );

    expect(WorkspaceMaterialSetting::query()->sole()->buffer_quantity)->toBe('1250.500000000');
});

it('rejects negative and over-precision buffer quantities', function (string $value, string $message): void {
    $fixture = materialBufferFixture();

    expect(fn () => app(SaveMaterialBuffer::class)->handle(
        $fixture['user'],
        $fixture['workspace'],
        $fixture['ingredient'],
        $value,
    ))->toThrow(ValidationException::class, $message);
})->with([
    'negative' => ['-1', 'Enter a valid non-negative quantity.'],
    'too precise' => ['1.1234567890', 'Use up to 11 digits before the decimal and 9 decimal places.'],
    'too large' => ['123456789012.000000000', 'Use up to 11 digits before the decimal and 9 decimal places.'],
]);

it('rejects a packaging buffer from another workspace', function (): void {
    $fixture = materialBufferFixture();
    $foreignWorkspace = Workspace::factory()->create();
    $packaging = PackagingItem::factory()->for($foreignWorkspace)->create();

    expect(fn () => app(SaveMaterialBuffer::class)->handle(
        $fixture['user'],
        $fixture['workspace'],
        $packaging,
        '10',
    ))->toThrow(AuthorizationException::class);
});

it('rejects an inaccessible ingredient buffer', function (): void {
    $fixture = materialBufferFixture();
    $otherUser = User::factory()->create();
    $ingredient = Ingredient::factory()->create([
        'owner_type' => OwnerType::User,
        'owner_id' => $otherUser->id,
        'visibility' => 'private',
    ]);

    expect(fn () => app(SaveMaterialBuffer::class)->handle(
        $fixture['user'],
        $fixture['workspace'],
        $ingredient,
        '10',
    ))->toThrow(ValidationException::class, 'This material is not available in the selected workspace.');
});

it('keeps read-only production bench buffers immutable', function (): void {
    $fixture = materialBufferFixture();
    app(ProductionBenchAccess::class)->cancel($fixture['user'], $fixture['workspace']);

    expect(fn () => app(SaveMaterialBuffer::class)->handle(
        $fixture['user'],
        $fixture['workspace'],
        $fixture['ingredient'],
        '10',
    ))->toThrow(ValidationException::class, __('production_bench.access.read_only'));
});

/**
 * @return array{user: User, workspace: Workspace, ingredient: Ingredient}
 */
function materialBufferFixture(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($user, $workspace);

    return [
        'user' => $user,
        'workspace' => $workspace,
        'ingredient' => Ingredient::factory()->create(),
    ];
}
