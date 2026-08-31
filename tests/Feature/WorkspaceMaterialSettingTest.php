<?php

use App\Actions\Inventory\SaveMaterialBuffer;
use App\Enums\MassDisplaySystem;
use App\Enums\OwnerType;
use App\Enums\ProductionBenchEntitlementStatus;
use App\Models\Ingredient;
use App\Models\PackagingItem;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMaterialSetting;
use App\Models\WorkspaceProductionEntitlement;
use App\Services\ProductionBenchAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('assigns a public uuid to material settings', function (): void {
    $setting = WorkspaceMaterialSetting::factory()->create();

    expect($setting->public_id)->toBeUuid()
        ->and($setting->getRouteKeyName())->toBe('public_id');
});

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

    $action->handle($fixture['user'], $fixture['workspace'], $fixture['ingredient'], '1.25');
    expect(WorkspaceMaterialSetting::query()->sole()->buffer_quantity)->toBe('1250.000000000');

    $action->handle($fixture['user'], $fixture['workspace'], $fixture['ingredient'], '0.9');
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

    expect(WorkspaceMaterialSetting::query()->sole()->buffer_quantity)->toBe('1250500.000000000');
});

it('converts an ingredient buffer from the workspace display unit', function (MassDisplaySystem $system, string $entered, string $stored): void {
    $fixture = materialBufferFixture($system);

    app(SaveMaterialBuffer::class)->handle(
        $fixture['user'],
        $fixture['workspace'],
        $fixture['ingredient'],
        $entered,
    );

    expect(WorkspaceMaterialSetting::query()->sole()->buffer_quantity)->toBe($stored);
})->with([
    'metric kilograms' => [MassDisplaySystem::Metric, '1.2', '1200.000000000'],
    'us customary pounds' => [MassDisplaySystem::UsCustomary, '2', '907.184740000'],
]);

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

it('rejects a buffer that overflows the canonical column only after conversion', function (MassDisplaySystem $system, string $entered): void {
    $fixture = materialBufferFixture($system);

    // Nine nines passes the 11-integer-digit check on the entered display value,
    // but conversion to canonical grams multiplies it past 11 integer digits and
    // would overflow decimal(20, 9) on PostgreSQL.
    expect(fn () => app(SaveMaterialBuffer::class)->handle(
        $fixture['user'],
        $fixture['workspace'],
        $fixture['ingredient'],
        $entered,
    ))->toThrow(ValidationException::class, 'That quantity is too large to store. Enter a smaller buffer.');
})->with([
    'metric kilograms' => [MassDisplaySystem::Metric, '999999999'],
    'us customary pounds' => [MassDisplaySystem::UsCustomary, '999999999'],
]);

it('re-asserts write access inside the buffer transaction', function (): void {
    $fixture = materialBufferFixture();

    // Cancel the entitlement at the instant the write transaction opens, i.e. after
    // any check that ran before it. .ai/rules/app.md requires access to be
    // re-asserted inside the transaction, so the buffer must not be persisted.
    Event::listen(TransactionBeginning::class, function () use ($fixture): void {
        WorkspaceProductionEntitlement::query()
            ->where('workspace_id', $fixture['workspace']->id)
            ->update(['status' => ProductionBenchEntitlementStatus::Cancelled]);
    });

    expect(fn () => app(SaveMaterialBuffer::class)->handle(
        $fixture['user'],
        $fixture['workspace'],
        $fixture['ingredient'],
        '1.25',
    ))->toThrow(ValidationException::class);

    expect(WorkspaceMaterialSetting::query()->count())->toBe(0);
});

it('accepts the largest metric buffer that still fits after conversion', function (): void {
    $fixture = materialBufferFixture();

    app(SaveMaterialBuffer::class)->handle(
        $fixture['user'],
        $fixture['workspace'],
        $fixture['ingredient'],
        '99999999',
    );

    expect(WorkspaceMaterialSetting::query()->sole()->buffer_quantity)->toBe('99999999000.000000000');
});

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
function materialBufferFixture(MassDisplaySystem $displaySystem = MassDisplaySystem::Metric): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user, 'owner')->create([
        'mass_display_system' => $displaySystem,
    ]);
    app(ProductionBenchAccess::class)->activate($user, $workspace);

    return [
        'user' => $user,
        'workspace' => $workspace,
        'ingredient' => Ingredient::factory()->create(),
    ];
}
