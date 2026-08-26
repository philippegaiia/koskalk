<?php

use App\Enums\OwnerType;
use App\Enums\Visibility;
use App\Enums\WorkspaceMemberRole;
use App\Models\Ingredient;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceIngredientCode;
use App\Models\WorkspaceMember;
use App\Services\WorkspaceIngredientCodeService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function workspacePrivateIngredient(Workspace $workspace, string $name = 'Olive oil'): Ingredient
{
    return Ingredient::factory()->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Private,
        'display_name' => $name,
    ]);
}

it('normalizes and stores a code for platform and private ingredients', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $platformIngredient = Ingredient::factory()->create(['display_name' => 'Coconut oil']);
    $privateIngredient = workspacePrivateIngredient($workspace);
    $service = app(WorkspaceIngredientCodeService::class);

    $platformCode = $service->synchronize($owner, $workspace, $platformIngredient, ' rm-olive_01 ');
    $privateCode = $service->synchronize($owner, $workspace, $privateIngredient, 'rm-coconut');

    expect($platformCode?->material_code)->toBe('RM-OLIVE_01')
        ->and($privateCode?->material_code)->toBe('RM-COCONUT')
        ->and($service->codeFor($workspace, $platformIngredient))->toBe('RM-OLIVE_01')
        ->and($service->codesFor($workspace, [$platformIngredient->id, $privateIngredient->id])->all())
        ->toBe([
            $platformIngredient->id => 'RM-OLIVE_01',
            $privateIngredient->id => 'RM-COCONUT',
        ]);
});

it('enforces uniqueness only among current assignments in a workspace', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $first = Ingredient::factory()->create();
    $second = Ingredient::factory()->create();
    $otherWorkspace = Workspace::factory()->create();
    $otherIngredient = workspacePrivateIngredient($otherWorkspace);
    $service = app(WorkspaceIngredientCodeService::class);

    $service->synchronize($owner, $workspace, $first, 'RM-OLIVE');

    expect(fn () => $service->synchronize($owner, $workspace, $second, 'rm-olive'))
        ->toThrow(ValidationException::class);

    $otherCode = $service->synchronize($otherWorkspace->owner, $otherWorkspace, $otherIngredient, 'RM-OLIVE');

    expect($otherCode?->material_code)->toBe('RM-OLIVE');
});

it('frees a code when it is changed or cleared so it can be reused', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $first = Ingredient::factory()->create();
    $second = Ingredient::factory()->create();
    $service = app(WorkspaceIngredientCodeService::class);

    $service->synchronize($owner, $workspace, $first, 'RM-OLIVE');
    $service->synchronize($owner, $workspace, $first, 'RM-OLIVE-NEW');
    $reused = $service->synchronize($owner, $workspace, $second, 'RM-OLIVE');

    expect($reused?->material_code)->toBe('RM-OLIVE');

    $service->synchronize($owner, $workspace, $first, null);

    expect(WorkspaceIngredientCode::query()->where('ingredient_id', $first->id)->exists())->toBeFalse()
        ->and($service->synchronize($owner, $workspace, $first, 'RM-OLIVE-NEW')?->material_code)
        ->toBe('RM-OLIVE-NEW');
});

it('rejects invalid values and foreign private ingredients', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $ingredient = Ingredient::factory()->create();
    $foreignWorkspace = Workspace::factory()->create();
    $foreignIngredient = workspacePrivateIngredient($foreignWorkspace, 'Foreign material');
    $service = app(WorkspaceIngredientCodeService::class);

    expect(fn () => $service->synchronize($owner, $workspace, $ingredient, 'not valid'))
        ->toThrow(ValidationException::class);

    expect(fn () => $service->synchronize($owner, $workspace, $ingredient, str_repeat('A', 65)))
        ->toThrow(ValidationException::class);

    expect(fn () => $service->synchronize($owner, $workspace, $foreignIngredient, 'RM-FOREIGN'))
        ->toThrow(ValidationException::class);
});

it('requires a writable workspace role', function (): void {
    $owner = User::factory()->create();
    $viewer = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    WorkspaceMember::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $viewer->id,
        'role' => WorkspaceMemberRole::Viewer,
    ]);
    $ingredient = Ingredient::factory()->create();
    $service = app(WorkspaceIngredientCodeService::class);

    expect(fn () => $service->synchronize($viewer, $workspace, $ingredient, 'RM-OLIVE'))
        ->toThrow(AuthorizationException::class);
});

it('defines the overlay uniqueness boundaries in the database', function (): void {
    expect(Schema::hasTable('workspace_ingredient_codes'))->toBeTrue()
        ->and(Schema::hasColumns('workspace_ingredient_codes', ['workspace_id', 'ingredient_id', 'material_code']))->toBeTrue();

    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $first = Ingredient::factory()->create();
    $second = Ingredient::factory()->create();

    WorkspaceIngredientCode::query()->create([
        'workspace_id' => $workspace->id,
        'ingredient_id' => $first->id,
        'material_code' => 'RM-OLIVE',
    ]);

    expect(fn () => WorkspaceIngredientCode::query()->create([
        'workspace_id' => $workspace->id,
        'ingredient_id' => $first->id,
        'material_code' => 'RM-COCONUT',
    ]))->toThrow(QueryException::class);

    expect(fn () => WorkspaceIngredientCode::query()->create([
        'workspace_id' => $workspace->id,
        'ingredient_id' => $second->id,
        'material_code' => 'RM-OLIVE',
    ]))->toThrow(QueryException::class);
});
