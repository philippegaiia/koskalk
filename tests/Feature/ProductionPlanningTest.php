<?php

use App\Actions\Production\CreateProductionDraft;
use App\Actions\Production\PlanProduction;
use App\Actions\Production\ScheduleProduction;
use App\Actions\Production\UpdateProductionPlan;
use App\MassUnit;
use App\Models\Ingredient;
use App\Models\PackagingItem;
use App\Models\ProductFamily;
use App\Models\ProductionRequirement;
use App\Models\ProductionRun;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\RecipePhase;
use App\Models\RecipeVersion;
use App\Models\RecipeVersionPackagingItem;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceProductionEntitlement;
use App\OwnerType;
use App\ProductionBasisKind;
use App\ProductionRunStatus;
use App\Visibility;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('scales soap ingredient requirements from the initial oil mass and packaging from expected units', function (): void {
    $fixture = productionPlanningTask2Fixture();
    $oil = Ingredient::factory()->create(['display_name' => 'Olive oil']);
    $fragrance = Ingredient::factory()->create(['display_name' => 'Lavender']);
    $phase = RecipePhase::factory()->for($fixture['version'])->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $fixture['workspace']->id,
        'workspace_id' => $fixture['workspace']->id,
        'visibility' => Visibility::Private,
        'name' => 'Oils',
        'slug' => 'oils',
        'sort_order' => 1,
    ]);
    RecipeItem::factory()->for($fixture['version'])->for($phase, 'recipePhase')->for($oil)->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $fixture['workspace']->id,
        'workspace_id' => $fixture['workspace']->id,
        'visibility' => Visibility::Private,
        'position' => 1,
        'percentage' => '60.0000',
    ]);
    RecipeItem::factory()->for($fixture['version'])->for($phase, 'recipePhase')->for($fragrance)->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $fixture['workspace']->id,
        'workspace_id' => $fixture['workspace']->id,
        'visibility' => Visibility::Private,
        'position' => 2,
        'percentage' => '2.5000',
    ]);
    $packaging = PackagingItem::factory()->for($fixture['workspace'])->create(['name' => 'Soap box']);
    RecipeVersionPackagingItem::query()->create([
        'recipe_version_id' => $fixture['version']->id,
        'packaging_item_id' => $packaging->id,
        'name' => 'Soap box',
        'components_per_unit' => '1.000',
        'notes' => null,
        'position' => 1,
    ]);

    $production = app(CreateProductionDraft::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        recipe: $fixture['recipe'],
        basisInputValue: '12',
        basisInputUnit: MassUnit::Kilogram,
        expectedUnits: 100,
        idempotencyKey: 'soap-plan-1',
    );

    $requirements = $production->requirements;

    expect($production->status)->toBe(ProductionRunStatus::Draft)
        ->and($production->basis_kind)->toBe(ProductionBasisKind::OilMass)
        ->and($production->basis_quantity_grams)->toBe('12000.000000000')
        ->and($requirements->where('kind', 'ingredient')->pluck('required_mass_grams')->all())
        ->toBe(['7200.000000000', '300.000000000'])
        ->and($requirements->where('kind', 'packaging')->first()->required_units)->toBe(100)
        ->and($requirements->where('kind', 'ingredient')->first()->subject_name_snapshot)->toBe('Olive oil')
        ->and($requirements->where('kind', 'ingredient')->first()->phase_name_snapshot)->toBe('Oils')
        ->and($requirements->where('kind', 'ingredient')->first()->percentage_snapshot)->toBe('60.000000000');
});

it('scales cosmetic ingredient requirements from total formula mass', function (): void {
    $fixture = productionPlanningTask2Fixture(calculationBasis: 'total_formula');
    $ingredient = Ingredient::factory()->create(['display_name' => 'Emulsifier']);
    $phase = RecipePhase::factory()->for($fixture['version'])->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $fixture['workspace']->id,
        'workspace_id' => $fixture['workspace']->id,
        'visibility' => Visibility::Private,
        'name' => 'Phase A',
        'slug' => 'phase-a',
    ]);
    RecipeItem::factory()->for($fixture['version'])->for($phase, 'recipePhase')->for($ingredient)->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $fixture['workspace']->id,
        'workspace_id' => $fixture['workspace']->id,
        'visibility' => Visibility::Private,
        'percentage' => '12.5000',
    ]);

    $production = app(CreateProductionDraft::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        recipe: $fixture['recipe'],
        basisInputValue: '2.5',
        basisInputUnit: 'kg',
        expectedUnits: 20,
        idempotencyKey: 'cosmetic-plan-1',
    );

    expect($production->basis_kind)->toBe(ProductionBasisKind::TotalFormulaMass)
        ->and($production->requirements->first()->required_mass_grams)->toBe('312.500000000')
        ->and($production->requirements->first()->percentage_snapshot)->toBe('12.500000000');
});

it('pins the latest published version and keeps that version when a newer one is published', function (): void {
    $fixture = productionPlanningTask2Fixture();
    $firstIngredient = Ingredient::factory()->create(['display_name' => 'First oil']);
    $secondIngredient = Ingredient::factory()->create(['display_name' => 'Second oil']);
    $firstPhase = productionPlanningTask2Phase($fixture['version'], $fixture['workspace'], 'First phase');
    productionPlanningTask2Item($fixture['version'], $firstPhase, $fixture['workspace'], $firstIngredient, '100.0000');

    $production = app(CreateProductionDraft::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        recipe: $fixture['recipe'],
        basisInputValue: '1',
        basisInputUnit: 'kg',
        expectedUnits: 10,
        idempotencyKey: 'version-pin-1',
    );

    $secondVersion = RecipeVersion::factory()->for($fixture['recipe'])->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $fixture['workspace']->id,
        'workspace_id' => $fixture['workspace']->id,
        'visibility' => Visibility::Private,
        'version_number' => 2,
        'is_current' => false,
        'batch_mass_grams' => '1000.000000000',
    ]);
    $secondPhase = productionPlanningTask2Phase($secondVersion, $fixture['workspace'], 'Second phase');
    productionPlanningTask2Item($secondVersion, $secondPhase, $fixture['workspace'], $secondIngredient, '100.0000');
    RecipeVersion::query()->whereKey($fixture['version']->id)->update(['archived_at' => now()]);

    $updated = app(UpdateProductionPlan::class)->handle(
        actor: $fixture['owner'],
        production: $production,
        basisInputValue: '2',
        basisInputUnit: 'kg',
        expectedUnits: 10,
    );

    expect($updated->recipe_version_id)->toBe($fixture['version']->id)
        ->and($updated->requirements->first()->ingredient_id)->toBe($firstIngredient->id)
        ->and($updated->requirements->first()->required_mass_grams)->toBe('2000.000000000');
});

it('rejects planning without a published version', function (): void {
    $fixture = productionPlanningTask2Fixture(withPublishedVersion: false);

    expect(fn (): ProductionRun => app(CreateProductionDraft::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        recipe: $fixture['recipe'],
        basisInputValue: '1',
        basisInputUnit: 'kg',
        expectedUnits: 10,
        idempotencyKey: 'unpublished-1',
    ))->toThrow(ValidationException::class);
});

it('plans despite missing prices and insufficient stock', function (): void {
    $fixture = productionPlanningTask2Fixture();
    $ingredient = Ingredient::factory()->create(['display_name' => 'Unpriced oil']);
    $phase = productionPlanningTask2Phase($fixture['version'], $fixture['workspace'], 'Oils');
    productionPlanningTask2Item($fixture['version'], $phase, $fixture['workspace'], $ingredient, '100.0000');

    $production = app(PlanProduction::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        recipe: $fixture['recipe'],
        basisInputValue: '3',
        basisInputUnit: 'kg',
        expectedUnits: 10,
        idempotencyKey: 'shortage-1',
        plannedFor: '2026-08-20',
    );

    expect($production->status)->toBe(ProductionRunStatus::Scheduled)
        ->and($production->planned_for?->toDateString())->toBe('2026-08-20')
        ->and($production->requirements)->toHaveCount(1);
});

it('schedules a draft and does not allow rescheduling a non-draft', function (): void {
    $fixture = productionPlanningTask2Fixture();
    $draft = app(CreateProductionDraft::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        recipe: $fixture['recipe'],
        basisInputValue: '1',
        basisInputUnit: 'kg',
        expectedUnits: 10,
        idempotencyKey: 'schedule-1',
    );

    $scheduled = app(ScheduleProduction::class)->handle($fixture['owner'], $draft);

    expect($scheduled->status)->toBe(ProductionRunStatus::Scheduled)
        ->and(fn (): ProductionRun => app(ScheduleProduction::class)->handle($fixture['owner'], $scheduled))
        ->toThrow(ValidationException::class);
});

it('rebuilds requirements only for draft or scheduled productions', function (): void {
    $fixture = productionPlanningTask2Fixture();
    $ingredient = Ingredient::factory()->create(['display_name' => 'Oil']);
    $phase = productionPlanningTask2Phase($fixture['version'], $fixture['workspace'], 'Oils');
    productionPlanningTask2Item($fixture['version'], $phase, $fixture['workspace'], $ingredient, '100.0000');
    $production = app(CreateProductionDraft::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        recipe: $fixture['recipe'],
        basisInputValue: '1',
        basisInputUnit: 'kg',
        expectedUnits: 10,
        idempotencyKey: 'update-1',
    );

    $updated = app(UpdateProductionPlan::class)->handle(
        actor: $fixture['owner'],
        production: $production,
        basisInputValue: '1.5',
        basisInputUnit: 'kg',
        expectedUnits: 12,
        plannedFor: '2026-09-01',
        notes: 'Updated plan',
    );

    expect($updated->basis_quantity_grams)->toBe('1500.000000000')
        ->and($updated->expected_units)->toBe(12)
        ->and($updated->requirements->first()->required_mass_grams)->toBe('1500.000000000')
        ->and($updated->notes)->toBe('Updated plan');

    $updated->update(['status' => ProductionRunStatus::Reserved]);

    expect(fn (): ProductionRun => app(UpdateProductionPlan::class)->handle(
        actor: $fixture['owner'],
        production: $updated,
        basisInputValue: '2',
        basisInputUnit: 'kg',
        expectedUnits: 12,
    ))->toThrow(ValidationException::class);
});

it('rejects a plan update while active stock reservations exist', function (): void {
    $fixture = productionPlanningTask2Fixture();
    $ingredient = Ingredient::factory()->create(['display_name' => 'Reserved oil']);
    $phase = productionPlanningTask2Phase($fixture['version'], $fixture['workspace'], 'Oils');
    productionPlanningTask2Item($fixture['version'], $phase, $fixture['workspace'], $ingredient, '100.0000');
    $production = app(CreateProductionDraft::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        recipe: $fixture['recipe'],
        basisInputValue: '1',
        basisInputUnit: 'kg',
        expectedUnits: 10,
        idempotencyKey: 'reserved-update',
    );

    // The production migration now creates this table for every test. Recreate
    // the minimal legacy shape here because this test only exercises the
    // lifecycle guard's status query.
    Schema::dropIfExists('stock_reservations');
    Schema::create('stock_reservations', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('production_run_id');
        $table->string('status');
    });

    try {
        DB::table('stock_reservations')->insert([
            'production_run_id' => $production->id,
            'status' => 'reserved',
        ]);

        expect(fn (): ProductionRun => app(UpdateProductionPlan::class)->handle(
            actor: $fixture['owner'],
            production: $production,
            basisInputValue: '2',
            basisInputUnit: 'kg',
            expectedUnits: 10,
        ))->toThrow(ValidationException::class);
    } finally {
        Schema::dropIfExists('stock_reservations');
    }
});

it('returns the existing production for a duplicate idempotency key without duplicating requirements', function (): void {
    $fixture = productionPlanningTask2Fixture();
    $ingredient = Ingredient::factory()->create(['display_name' => 'Oil']);
    $phase = productionPlanningTask2Phase($fixture['version'], $fixture['workspace'], 'Oils');
    productionPlanningTask2Item($fixture['version'], $phase, $fixture['workspace'], $ingredient, '100.0000');
    $action = app(CreateProductionDraft::class);

    $first = $action->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        recipe: $fixture['recipe'],
        basisInputValue: '1',
        basisInputUnit: 'kg',
        expectedUnits: 10,
        idempotencyKey: 'same-key',
    );
    $second = $action->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        recipe: $fixture['recipe'],
        basisInputValue: '1',
        basisInputUnit: 'kg',
        expectedUnits: 10,
        idempotencyKey: 'same-key',
    );

    expect($second->is($first))->toBeTrue()
        ->and(ProductionRun::query()->where('workspace_id', $fixture['workspace']->id)->count())->toBe(1)
        ->and(ProductionRequirement::query()->where('production_run_id', $first->id)->count())->toBe(1);
});

it('rejects cross-workspace recipes and read-only production bench mutations', function (): void {
    $fixture = productionPlanningTask2Fixture();
    $other = productionPlanningTask2Fixture();

    expect(fn (): ProductionRun => app(CreateProductionDraft::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        recipe: $other['recipe'],
        basisInputValue: '1',
        basisInputUnit: 'kg',
        expectedUnits: 10,
        idempotencyKey: 'cross-workspace',
    ))->toThrow(ValidationException::class);

    $fixture['workspace']->productionEntitlement()->update([
        'status' => 'cancelled',
        'cancelled_at' => now(),
    ]);

    expect(fn (): ProductionRun => app(PlanProduction::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        recipe: $fixture['recipe'],
        basisInputValue: '1',
        basisInputUnit: 'kg',
        expectedUnits: 10,
        idempotencyKey: 'read-only',
    ))->toThrow(ValidationException::class);
});

it('rejects fractional expected units and invalid production dates', function (): void {
    $fixture = productionPlanningTask2Fixture();

    expect(fn (): ProductionRun => app(CreateProductionDraft::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        recipe: $fixture['recipe'],
        basisInputValue: '1',
        basisInputUnit: 'kg',
        expectedUnits: '1.5',
        idempotencyKey: 'fractional-units',
    ))->toThrow(ValidationException::class);

    expect(fn (): ProductionRun => app(PlanProduction::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        recipe: $fixture['recipe'],
        basisInputValue: '1',
        basisInputUnit: 'kg',
        expectedUnits: 1,
        idempotencyKey: 'invalid-date',
        plannedFor: '2026-02-31',
    ))->toThrow(ValidationException::class);
});

it('rejects recipe materials from another workspace', function (): void {
    $fixture = productionPlanningTask2Fixture();
    $other = productionPlanningTask2Fixture();
    $ingredient = Ingredient::factory()->create([
        'display_name' => 'Foreign oil',
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $other['workspace']->id,
        'workspace_id' => $other['workspace']->id,
        'visibility' => Visibility::Private,
    ]);
    $phase = productionPlanningTask2Phase($fixture['version'], $fixture['workspace'], 'Oils');
    productionPlanningTask2Item($fixture['version'], $phase, $fixture['workspace'], $ingredient, '100.0000');

    expect(fn (): ProductionRun => app(CreateProductionDraft::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        recipe: $fixture['recipe'],
        basisInputValue: '1',
        basisInputUnit: 'kg',
        expectedUnits: 10,
        idempotencyKey: 'foreign-material',
    ))->toThrow(ValidationException::class);
});

it('rejects a published packaging requirement whose catalogue item is missing', function (): void {
    $fixture = productionPlanningTask2Fixture();

    RecipeVersionPackagingItem::query()->create([
        'recipe_version_id' => $fixture['version']->id,
        'packaging_item_id' => null,
        'name' => 'Deleted box',
        'components_per_unit' => '1.000',
        'position' => 1,
    ]);

    expect(fn (): ProductionRun => app(CreateProductionDraft::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        recipe: $fixture['recipe'],
        basisInputValue: '1',
        basisInputUnit: 'kg',
        expectedUnits: 10,
        idempotencyKey: 'missing-packaging',
    ))->toThrow(ValidationException::class);
});

/**
 * @return array{owner: User, workspace: Workspace, recipe: Recipe, version: RecipeVersion}
 */
function productionPlanningTask2Fixture(string $calculationBasis = 'initial_oils', bool $withPublishedVersion = true): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    WorkspaceProductionEntitlement::factory()->for($workspace)->create();
    $family = ProductFamily::factory()->create([
        'slug' => ($calculationBasis === 'total_formula' ? 'cosmetic-' : 'soap-').fake()->unique()->numberBetween(1, 999999),
        'calculation_basis' => $calculationBasis,
    ]);
    $recipe = Recipe::factory()->for($family, 'productFamily')->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Private,
    ]);
    $version = RecipeVersion::factory()->for($recipe)->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Private,
        'is_current' => $withPublishedVersion ? false : true,
        'batch_mass_grams' => '1000.000000000',
    ]);

    return compact('owner', 'workspace', 'recipe', 'version');
}

function productionPlanningTask2Phase(RecipeVersion $version, Workspace $workspace, string $name): RecipePhase
{
    return RecipePhase::factory()->for($version)->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Private,
        'name' => $name,
        'slug' => str($name)->slug(),
    ]);
}

function productionPlanningTask2Item(
    RecipeVersion $version,
    RecipePhase $phase,
    Workspace $workspace,
    Ingredient $ingredient,
    string $percentage,
): RecipeItem {
    return RecipeItem::factory()->for($version)->for($phase, 'recipePhase')->for($ingredient)->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Private,
        'percentage' => $percentage,
    ]);
}
