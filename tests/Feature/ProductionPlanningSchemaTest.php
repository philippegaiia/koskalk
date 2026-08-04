<?php

use App\MassUnit;
use App\Models\Ingredient;
use App\Models\PackagingItem;
use App\Models\ProductionRequirement;
use App\Models\ProductionRun;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Models\WorkspaceProductionEntitlement;
use App\OwnerType;
use App\ProductionBasisKind;
use App\ProductionBenchEntitlementStatus;
use App\ProductionRequirementKind;
use App\ProductionRunSource;
use App\ProductionRunStatus;
use App\Visibility;
use App\WorkspaceMemberRole;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('creates the production planning tables with their durable snapshot columns', function (): void {
    expect(Schema::hasColumns('production_runs', [
        'public_id',
        'workspace_id',
        'recipe_id',
        'recipe_version_id',
        'status',
        'source',
        'planned_for',
        'basis_kind',
        'basis_quantity_grams',
        'basis_input_value',
        'basis_input_unit',
        'expected_units',
        'notes',
        'idempotency_key',
        'created_by_user_id',
        'cancelled_at',
        'cancelled_by_user_id',
        'cancellation_reason',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('production_requirements', [
            'production_run_id',
            'ingredient_id',
            'packaging_item_id',
            'recipe_item_id',
            'recipe_version_packaging_item_id',
            'kind',
            'required_mass_grams',
            'required_units',
            'subject_name_snapshot',
            'phase_key_snapshot',
            'phase_name_snapshot',
            'percentage_snapshot',
            'components_per_unit_snapshot',
            'unit_snapshot',
            'sort_order',
        ]))->toBeTrue();
});

it('defines the complete production planning enum contract', function (): void {
    expect(array_column(ProductionRunStatus::cases(), 'value'))->toBe([
        'draft',
        'scheduled',
        'reserved',
        'in_production',
        'completed',
        'cancelled',
        'aborted',
    ])->and(array_column(ProductionRunSource::cases(), 'value'))->toBe([
        'direct',
        'flash',
    ])->and(array_column(ProductionBasisKind::cases(), 'value'))->toBe([
        'oil_mass',
        'total_formula_mass',
    ])->and(array_column(ProductionRequirementKind::cases(), 'value'))->toBe([
        'ingredient',
        'packaging',
    ]);
});

it('provides the customer-facing production status labels', function (): void {
    expect(method_exists(ProductionRunStatus::class, 'label'))->toBeTrue();

    expect(array_map(
        fn (ProductionRunStatus $status): string => $status->label(),
        ProductionRunStatus::cases(),
    ))->toBe([
        'Draft',
        'Planned',
        'Stock prepared',
        'In production',
        'Completed',
        'Cancelled',
        'Aborted',
    ]);
});

it('casts production values precisely and exposes the complete relationship graph', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $recipe = productionPlanningRecipe($workspace);
    $version = productionPlanningVersion($recipe, $workspace);
    $cancelledBy = User::factory()->create();

    $production = ProductionRun::factory()
        ->for($workspace)
        ->for($recipe)
        ->for($version, 'recipeVersion')
        ->for($owner, 'createdBy')
        ->for($cancelledBy, 'cancelledBy')
        ->create([
            'status' => ProductionRunStatus::Cancelled,
            'source' => ProductionRunSource::Flash,
            'planned_for' => '2026-08-20',
            'basis_kind' => ProductionBasisKind::OilMass,
            'basis_quantity_grams' => '12000.123456789',
            'basis_input_value' => '12.000123457',
            'basis_input_unit' => 'kg',
            'expected_units' => 100,
            'cancelled_at' => '2026-08-10 10:30:00',
        ]);

    $ingredientRequirement = ProductionRequirement::factory()
        ->for($production)
        ->create([
            'sort_order' => 2,
            'required_mass_grams' => '0.123456789',
        ]);
    $packagingRequirement = ProductionRequirement::factory()
        ->for($production)
        ->forPackaging()
        ->create([
            'sort_order' => 1,
            'required_units' => 100,
        ]);

    expect($production->public_id)->not->toBeEmpty()
        ->and($production->getRouteKeyName())->toBe('public_id')
        ->and($production->status)->toBe(ProductionRunStatus::Cancelled)
        ->and($production->source)->toBe(ProductionRunSource::Flash)
        ->and($production->basis_kind)->toBe(ProductionBasisKind::OilMass)
        ->and($production->planned_for?->toDateString())->toBe('2026-08-20')
        ->and($production->basis_quantity_grams)->toBe('12000.123456789')
        ->and($production->basis_input_value)->toBe('12.000123457')
        ->and($production->basis_input_unit)->toBe(MassUnit::Kilogram)
        ->and($production->expected_units)->toBe(100)
        ->and($production->workspace->is($workspace))->toBeTrue()
        ->and($production->recipe->is($recipe))->toBeTrue()
        ->and($production->recipeVersion->is($version))->toBeTrue()
        ->and($production->createdBy->is($owner))->toBeTrue()
        ->and($production->cancelledBy->is($cancelledBy))->toBeTrue()
        ->and($production->requirements->modelKeys())->toBe([
            $packagingRequirement->id,
            $ingredientRequirement->id,
        ])
        ->and($ingredientRequirement->kind)->toBe(ProductionRequirementKind::Ingredient)
        ->and($ingredientRequirement->required_mass_grams)->toBe('0.123456789')
        ->and($ingredientRequirement->required_units)->toBeNull()
        ->and($packagingRequirement->kind)->toBe(ProductionRequirementKind::Packaging)
        ->and($packagingRequirement->required_units)->toBe(100)
        ->and($packagingRequirement->packagingItem->workspace_id)->toBe($workspace->id)
        ->and($workspace->productionRuns->contains($production))->toBeTrue()
        ->and($recipe->productionRuns->contains($production))->toBeTrue()
        ->and($version->productionRuns->contains($production))->toBeTrue();
});

it('builds a coherent published production factory graph', function (): void {
    $production = ProductionRun::factory()->create();

    expect($production->workspace_id)->toBe($production->recipe->workspace_id)
        ->and($production->recipe_id)->toBe($production->recipeVersion->recipe_id)
        ->and($production->workspace_id)->toBe($production->recipeVersion->workspace_id)
        ->and($production->recipeVersion->is_current)->toBeFalse()
        ->and($production->created_by_user_id)->toBe($production->workspace->owner_user_id);
});

it('keeps production idempotency keys unique inside one workspace only', function (): void {
    $firstWorkspace = Workspace::factory()->create();
    $secondWorkspace = Workspace::factory()->create();

    ProductionRun::factory()->for($firstWorkspace)->create([
        'idempotency_key' => 'plan-production-42',
    ]);
    ProductionRun::factory()->for($secondWorkspace)->create([
        'idempotency_key' => 'plan-production-42',
    ]);

    expect(fn (): ProductionRun => ProductionRun::factory()->for($firstWorkspace)->create([
        'idempotency_key' => 'plan-production-42',
    ]))->toThrow(QueryException::class);
});

it('requires positive production quantities', function (array $attributes): void {
    expect(fn (): ProductionRun => ProductionRun::factory()->create($attributes))
        ->toThrow(QueryException::class);
})->with([
    'zero canonical basis' => [['basis_quantity_grams' => '0']],
    'negative entered basis' => [['basis_input_value' => '-1']],
    'zero expected units' => [['expected_units' => 0]],
]);

it('enforces production value integrity on updates', function (): void {
    $production = ProductionRun::factory()->create();

    expect(fn (): int => DB::table('production_runs')
        ->where('id', $production->id)
        ->update(['basis_quantity_grams' => '0']))->toThrow(QueryException::class);
});

it('requires exactly one correctly typed requirement subject and quantity', function (): void {
    $production = ProductionRun::factory()->create();
    $ingredient = Ingredient::factory()->create();
    $packagingItem = PackagingItem::factory()->for($production->workspace)->create();

    ProductionRequirement::factory()
        ->for($production)
        ->for($ingredient)
        ->create(['required_mass_grams' => '0.000000001']);
    ProductionRequirement::factory()
        ->for($production)
        ->forPackaging($packagingItem)
        ->create(['required_units' => 2]);

    $invalidRows = [
        'both subjects' => [
            'ingredient_id' => $ingredient->id,
            'packaging_item_id' => $packagingItem->id,
        ],
        'no subject' => [
            'ingredient_id' => null,
            'packaging_item_id' => null,
        ],
        'ingredient with count kind' => [
            'ingredient_id' => $ingredient->id,
            'packaging_item_id' => null,
            'kind' => ProductionRequirementKind::Packaging,
        ],
        'ingredient with count quantity' => [
            'ingredient_id' => $ingredient->id,
            'packaging_item_id' => null,
            'kind' => ProductionRequirementKind::Ingredient,
            'required_mass_grams' => null,
            'required_units' => 1,
        ],
        'ingredient without a quantity' => [
            'ingredient_id' => $ingredient->id,
            'packaging_item_id' => null,
            'kind' => ProductionRequirementKind::Ingredient,
            'required_mass_grams' => null,
            'required_units' => null,
        ],
        'packaging with mass quantity' => [
            'ingredient_id' => null,
            'packaging_item_id' => $packagingItem->id,
            'kind' => ProductionRequirementKind::Packaging,
            'required_mass_grams' => '1',
            'required_units' => null,
        ],
        'zero mass' => [
            'required_mass_grams' => '0',
        ],
        'zero units' => [
            'ingredient_id' => null,
            'packaging_item_id' => $packagingItem->id,
            'kind' => ProductionRequirementKind::Packaging,
            'required_mass_grams' => null,
            'required_units' => 0,
        ],
        'packaging without a quantity' => [
            'ingredient_id' => null,
            'packaging_item_id' => $packagingItem->id,
            'kind' => ProductionRequirementKind::Packaging,
            'required_mass_grams' => null,
            'required_units' => null,
        ],
    ];

    foreach ($invalidRows as $attributes) {
        expect(fn (): ProductionRequirement => ProductionRequirement::factory()
            ->for($production)
            ->for($ingredient)
            ->create($attributes))->toThrow(QueryException::class);
    }
});

it('rejects fractional packaging quantities at the database boundary', function (): void {
    $production = ProductionRun::factory()->create();
    $packagingItem = PackagingItem::factory()->for($production->workspace)->create();

    expect(fn (): bool => DB::table('production_requirements')->insert([
        'production_run_id' => $production->id,
        'ingredient_id' => null,
        'packaging_item_id' => $packagingItem->id,
        'recipe_item_id' => null,
        'recipe_version_packaging_item_id' => null,
        'kind' => ProductionRequirementKind::Packaging->value,
        'required_mass_grams' => null,
        'required_units' => 2.5,
        'subject_name_snapshot' => 'Bottle',
        'phase_key_snapshot' => null,
        'phase_name_snapshot' => null,
        'percentage_snapshot' => null,
        'components_per_unit_snapshot' => '1.000000000',
        'unit_snapshot' => 'unit',
        'sort_order' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('enforces requirement integrity on updates as well as inserts', function (): void {
    $requirement = ProductionRequirement::factory()->create();

    expect(fn (): bool => DB::table('production_requirements')
        ->where('id', $requirement->id)
        ->update([
            'required_mass_grams' => null,
            'required_units' => 1,
        ]))->toThrow(QueryException::class);

    expect(fn (): int => DB::table('production_requirements')
        ->where('id', $requirement->id)
        ->update([
            'required_mass_grams' => null,
            'required_units' => null,
        ]))->toThrow(QueryException::class);
});

it('authorizes production records by workspace role and active entitlement', function (): void {
    $owner = User::factory()->create();
    $editor = User::factory()->create();
    $viewer = User::factory()->create();
    $outsider = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    WorkspaceProductionEntitlement::factory()->for($workspace)->create();
    WorkspaceMember::factory()->for($workspace)->for($editor)->create([
        'role' => WorkspaceMemberRole::Editor,
    ]);
    WorkspaceMember::factory()->for($workspace)->for($viewer)->create([
        'role' => WorkspaceMemberRole::Viewer,
    ]);
    $production = ProductionRun::factory()->for($workspace)->create();

    expect(Gate::forUser($owner)->allows('view', $production))->toBeTrue()
        ->and(Gate::forUser($editor)->allows('view', $production))->toBeTrue()
        ->and(Gate::forUser($viewer)->allows('view', $production))->toBeTrue()
        ->and(Gate::forUser($outsider)->allows('view', $production))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('create', [ProductionRun::class, $workspace]))->toBeTrue()
        ->and(Gate::forUser($editor)->allows('update', $production))->toBeTrue()
        ->and(Gate::forUser($viewer)->allows('update', $production))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('delete', $production))->toBeFalse();

    $workspace->productionEntitlement()->update([
        'status' => ProductionBenchEntitlementStatus::Cancelled,
        'cancelled_at' => now(),
    ]);

    expect(Gate::forUser($owner)->allows('view', $production))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('create', [ProductionRun::class, $workspace]))->toBeFalse()
        ->and(Gate::forUser($editor)->allows('update', $production))->toBeFalse();
});

function productionPlanningRecipe(Workspace $workspace): Recipe
{
    return Recipe::factory()->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Private,
    ]);
}

function productionPlanningVersion(Recipe $recipe, Workspace $workspace): RecipeVersion
{
    return RecipeVersion::factory()->for($recipe)->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Private,
        'is_current' => false,
    ]);
}
