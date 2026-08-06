<?php

use App\Actions\Production\CreateProductionDraft;
use App\Actions\Production\DeleteProductionRun;
use App\Actions\Production\GenerateProductionTasks;
use App\Actions\Production\PlanProduction;
use App\Actions\Production\ScheduleProduction;
use App\Actions\Production\UpdateProductionPlan;
use App\MassUnit;
use App\Models\FattyAcid;
use App\Models\Ingredient;
use App\Models\IngredientFattyAcid;
use App\Models\IngredientSapProfile;
use App\Models\PackagingItem;
use App\Models\ProductFamily;
use App\Models\ProductionFormulaLine;
use App\Models\ProductionRequirement;
use App\Models\ProductionRun;
use App\Models\ProductionRunNumberSetting;
use App\Models\ProductionTask;
use App\Models\ProductionTaskSet;
use App\Models\ProductionTaskSetItem;
use App\Models\ProductionTaskType;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\RecipePhase;
use App\Models\RecipeVersion;
use App\Models\RecipeVersionPackagingItem;
use App\Models\StockLot;
use App\Models\StockReservation;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceProductionEntitlement;
use App\OwnerType;
use App\ProductionBasisKind;
use App\ProductionRunStatus;
use App\StockReservationStatus;
use App\Visibility;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('rejects archived products before creating a production plan', function (): void {
    $fixture = productionPlanningTask2Fixture();
    $fixture['recipe']->update(['archived_at' => now()]);

    expect(fn (): ProductionRun => app(CreateProductionDraft::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        recipe: $fixture['recipe'],
        basisInputValue: '12',
        basisInputUnit: MassUnit::Kilogram,
        expectedUnits: 100,
        idempotencyKey: 'archived-product-plan',
    ))->toThrow(ValidationException::class);

    expect($fixture['workspace']->productionRuns()->count())->toBe(0);
});

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
        ->and($first->planning_batch_number)->toBe('T00001')
        ->and($second->planning_batch_number)->toBe('T00001')
        ->and(ProductionRun::query()->where('workspace_id', $fixture['workspace']->id)->count())->toBe(1)
        ->and(ProductionRequirement::query()->where('production_run_id', $first->id)->count())->toBe(1)
        ->and(ProductionRunNumberSetting::query()->whereBelongsTo($fixture['workspace'])->sole()->next_planning_serial)->toBe(2);
});

it('rolls back a direct planning reference when task generation fails', function (): void {
    $fixture = productionPlanningTask2Fixture();
    $ingredient = Ingredient::factory()->create(['display_name' => 'Oil']);
    $phase = productionPlanningTask2Phase($fixture['version'], $fixture['workspace'], 'Oils');
    productionPlanningTask2Item($fixture['version'], $phase, $fixture['workspace'], $ingredient, '100.0000');
    ProductionRunNumberSetting::query()->create([
        'workspace_id' => $fixture['workspace']->id,
        'next_planning_serial' => 17,
    ]);
    $foreignOwner = User::factory()->create();
    $foreignWorkspace = Workspace::factory()->for($foreignOwner, 'owner')->create();
    $taskSet = ProductionTaskSet::factory()->for($fixture['workspace'])->create([
        'name' => 'Foreign task type',
    ]);
    ProductionTaskSetItem::factory()
        ->for($taskSet, 'taskSet')
        ->for(ProductionTaskType::factory()->for($foreignWorkspace), 'taskType')
        ->create([
            'position' => 1,
            'days_after_production' => 0,
        ]);
    $taskSet->recipes()->attach($fixture['recipe']->id, ['is_default' => false]);

    expect(fn (): ProductionRun => app(PlanProduction::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        recipe: $fixture['recipe'],
        basisInputValue: '1',
        basisInputUnit: 'kg',
        expectedUnits: 10,
        idempotencyKey: 'task-generation-failure',
        plannedFor: '2026-08-10',
        taskSet: $taskSet,
    ))->toThrow(ValidationException::class);

    expect(ProductionRun::query()->where('workspace_id', $fixture['workspace']->id)->count())->toBe(0)
        ->and(ProductionRunNumberSetting::query()->whereBelongsTo($fixture['workspace'])->sole()->next_planning_serial)->toBe(17);
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
it('persists the complete formula snapshot atomically when planning', function (): void {
    $fixture = productionPlanningTask3SoapFixture();

    $production = app(CreateProductionDraft::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        recipe: $fixture['recipe'],
        basisInputValue: '14',
        basisInputUnit: MassUnit::Kilogram,
        expectedUnits: 100,
        idempotencyKey: 'snapshot-plan-1',
    );

    $duplicate = app(CreateProductionDraft::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        recipe: $fixture['recipe'],
        basisInputValue: '14',
        basisInputUnit: MassUnit::Kilogram,
        expectedUnits: 100,
        idempotencyKey: 'snapshot-plan-1',
    );

    expect($duplicate->id)->toBe($production->id)
        ->and($production->recipe_name_snapshot)->toBe($fixture['recipe']->name)
        ->and($production->source_formula_version_number)->toBe($fixture['version']->version_number)
        ->and($production->formula_snapshot_completed_at)->not->toBeNull()
        ->and($production->formula_context_snapshot)->toBe([
            'calculation_basis' => 'initial_oils',
            'lye_type' => 'naoh',
            'superfat_percentage' => 5,
            'water_mode' => 'percent_of_oils',
            'water_value' => 38,
        ])
        ->and($production->formulaLines()->count())->toBe(4)
        ->and($production->formulaLines()->where('component', 'ingredient')->pluck('planned_mass_grams')->all())
        ->toBe(['10500.000000000', '3500.000000000'])
        ->and($production->formulaLines()->where('component', 'naoh')->count())->toBe(1)
        ->and($production->formulaLines()->where('component', 'water')->count())->toBe(1)
        ->and($production->requirements()->where('kind', 'ingredient')->count())->toBe(2)
        ->and($production->requirements()->where('kind', 'packaging')->count())->toBe(1)
        ->and($production->requirements()->count())->toBe(3)
        ->and(ProductionFormulaLine::query()->where('production_run_id', $production->id)->count())->toBe(4);
});

it('persists the explicitly selected task set at creation', function (): void {
    $fixture = productionPlanningTask3SoapFixture();
    $taskType = ProductionTaskType::factory()->for($fixture['workspace'])->create(['name' => 'Cure']);
    $taskSet = ProductionTaskSet::factory()->for($fixture['workspace'])->create(['name' => 'Soap workflow']);
    ProductionTaskSetItem::factory()->for($taskSet, 'taskSet')->for($taskType, 'taskType')->create([
        'position' => 1,
        'days_after_production' => 0,
    ]);
    $taskSet->recipes()->attach($fixture['recipe']->id, ['is_default' => false]);

    $production = app(CreateProductionDraft::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        recipe: $fixture['recipe'],
        basisInputValue: '14',
        basisInputUnit: MassUnit::Kilogram,
        expectedUnits: 100,
        idempotencyKey: 'snapshot-task-set-1',
        taskSet: $taskSet,
    );

    expect($production->production_task_set_id)->toBe($taskSet->id)
        ->and($production->taskSet->is($taskSet))->toBeTrue();
});

it('rolls back the entire production when the formula snapshot cannot be built', function (): void {
    $fixture = productionPlanningTask3SoapFixture(withSap: false);

    expect(fn (): ProductionRun => app(CreateProductionDraft::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        recipe: $fixture['recipe'],
        basisInputValue: '14',
        basisInputUnit: MassUnit::Kilogram,
        expectedUnits: 100,
        idempotencyKey: 'snapshot-rollback-1',
    ))->toThrow(ValidationException::class);

    expect(ProductionRun::query()->count())->toBe(0)
        ->and(ProductionFormulaLine::query()->count())->toBe(0)
        ->and(ProductionRequirement::query()->count())->toBe(0);
});

it('keeps the production aggregate loadable when the version link is removed', function (): void {
    $fixture = productionPlanningTask3SoapFixture();
    $production = app(CreateProductionDraft::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        recipe: $fixture['recipe'],
        basisInputValue: '14',
        basisInputUnit: MassUnit::Kilogram,
        expectedUnits: 100,
        idempotencyKey: 'snapshot-no-version-1',
    );

    $production->update(['recipe_version_id' => null]);

    $loaded = ProductionRun::query()->with(['requirements', 'formulaLines'])->findOrFail($production->id);

    expect($loaded->recipe_version_id)->toBeNull()
        ->and($loaded->displayRecipeName())->toBe($fixture['recipe']->name)
        ->and($loaded->requirements()->count())->toBe(3)
        ->and($loaded->formulaLines()->count())->toBe(4);
});

it('rescales snapshot quantities in place and keeps reservation history', function (): void {
    $fixture = productionPlanningTask3SoapFixture();
    $production = app(CreateProductionDraft::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        recipe: $fixture['recipe'],
        basisInputValue: '14',
        basisInputUnit: MassUnit::Kilogram,
        expectedUnits: 100,
        idempotencyKey: 'rescale-plan-1',
    );

    $requirementIds = $production->requirements()->pluck('id')->all();
    $lineIds = $production->formulaLines()->pluck('id')->all();
    $oliveRequirement = $production->requirements()
        ->where('kind', 'ingredient')
        ->orderBy('id')
        ->firstOrFail();
    $oliveLine = $production->formulaLines()
        ->where('component', 'ingredient')
        ->orderBy('sort_order')
        ->firstOrFail();
    $oldNaohMass = $production->formulaLines()->where('component', 'naoh')->firstOrFail()->planned_mass_grams;
    $oldWaterMass = $production->formulaLines()->where('component', 'water')->firstOrFail()->planned_mass_grams;
    $lot = StockLot::factory()->released()->for($fixture['workspace'])->for($fixture['olive'])->create();
    $reservation = StockReservation::factory()->released()->create([
        'workspace_id' => $fixture['workspace']->id,
        'production_run_id' => $production->id,
        'production_requirement_id' => $oliveRequirement->id,
        'stock_lot_id' => $lot->id,
        'created_by_user_id' => $fixture['owner']->id,
    ]);

    $updated = app(UpdateProductionPlan::class)->handle(
        actor: $fixture['owner'],
        production: $production,
        basisInputValue: '20',
        basisInputUnit: MassUnit::Kilogram,
        expectedUnits: 150,
        plannedFor: '2026-09-01',
    );

    expect($updated->basis_quantity_grams)->toBe('20000.000000000')
        ->and($updated->expected_units)->toBe(150)
        ->and($updated->requirements()->pluck('id')->all())->toBe($requirementIds)
        ->and($updated->formulaLines()->pluck('id')->all())->toBe($lineIds)
        ->and($updated->requirements()->where('kind', 'ingredient')->orderBy('id')->pluck('required_mass_grams')->all())
        ->toBe(['15000.000000000', '5000.000000000'])
        ->and($updated->requirements()->where('kind', 'packaging')->firstOrFail()->required_units)->toBe(150)
        ->and($updated->formulaLines()->where('component', 'ingredient')->orderBy('sort_order')->firstOrFail()->planned_mass_grams)
        ->toBe('15000.000000000')
        ->and(((float) $updated->formulaLines()->where('component', 'naoh')->firstOrFail()->planned_mass_grams) / (float) $oldNaohMass)
        ->toBeGreaterThan(1.428)->toBeLessThan(1.429)
        ->and(((float) $updated->formulaLines()->where('component', 'water')->firstOrFail()->planned_mass_grams) / (float) $oldWaterMass)
        ->toBeGreaterThan(1.428)->toBeLessThan(1.429)
        ->and($oliveRequirement->fresh()->id)->toBe($oliveRequirement->id)
        ->and($reservation->fresh()->status)->toBe(StockReservationStatus::Released)
        ->and($reservation->fresh()->production_requirement_id)->toBe($oliveRequirement->id)
        ->and($updated->formulaLines()->count())->toBe($production->formulaLines()->count());
});

it('corrects quantities without the source version or a live recipe lookup', function (): void {
    $fixture = productionPlanningTask3SoapFixture();
    $production = app(CreateProductionDraft::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        recipe: $fixture['recipe'],
        basisInputValue: '14',
        basisInputUnit: MassUnit::Kilogram,
        expectedUnits: 100,
        idempotencyKey: 'rescale-no-version-1',
    );

    $production->update(['recipe_version_id' => null]);
    RecipeVersion::withoutGlobalScopes()
        ->whereKey($fixture['version']->id)
        ->delete();

    $updated = app(UpdateProductionPlan::class)->handle(
        actor: $fixture['owner'],
        production: $production,
        basisInputValue: '1.5',
        basisInputUnit: MassUnit::Kilogram,
        expectedUnits: 12,
    );

    expect($updated->basis_quantity_grams)->toBe('1500.000000000')
        ->and($updated->expected_units)->toBe(12)
        ->and($updated->recipe_version_id)->toBeNull()
        ->and($updated->requirements()->count())->toBe(3)
        ->and($updated->formulaLines()->count())->toBe(4);
});

it('rejects correction outside draft or scheduled status', function (string $status): void {
    $fixture = productionPlanningTask3SoapFixture();
    $production = app(CreateProductionDraft::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        recipe: $fixture['recipe'],
        basisInputValue: '14',
        basisInputUnit: MassUnit::Kilogram,
        expectedUnits: 100,
        idempotencyKey: 'rescale-status-'.fake()->unique()->numberBetween(1, 99999),
    );
    $production->update(['status' => $status]);

    expect(fn (): ProductionRun => app(UpdateProductionPlan::class)->handle(
        actor: $fixture['owner'],
        production: $production,
        basisInputValue: '20',
        basisInputUnit: MassUnit::Kilogram,
        expectedUnits: 150,
    ))->toThrow(ValidationException::class);
})->with([
    'reserved' => [ProductionRunStatus::Reserved->value],
    'in production' => [ProductionRunStatus::InProduction->value],
    'completed' => [ProductionRunStatus::Completed->value],
    'cancelled' => [ProductionRunStatus::Cancelled->value],
    'aborted' => [ProductionRunStatus::Aborted->value],
]);

it('resolves and pins the product default task set at creation', function (): void {
    $fixture = productionPlanningTask3SoapFixture();
    $taskType = ProductionTaskType::factory()->for($fixture['workspace'])->create(['name' => 'Cure']);
    $taskSet = ProductionTaskSet::factory()->for($fixture['workspace'])->create(['name' => 'Default soap workflow']);
    ProductionTaskSetItem::factory()->for($taskSet, 'taskSet')->for($taskType, 'taskType')->create([
        'position' => 1,
        'days_after_production' => 0,
    ]);
    $taskSet->recipes()->attach($fixture['recipe']->id, ['is_default' => true]);

    $production = app(CreateProductionDraft::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        recipe: $fixture['recipe'],
        basisInputValue: '14',
        basisInputUnit: MassUnit::Kilogram,
        expectedUnits: 100,
        idempotencyKey: 'task-default-1',
    );

    expect($production->production_task_set_id)->toBe($taskSet->id);
});

it('prefers the explicitly selected applicable task set over the product default', function (): void {
    $fixture = productionPlanningTask3SoapFixture();
    $taskType = ProductionTaskType::factory()->for($fixture['workspace'])->create(['name' => 'Cure']);
    $defaultSet = ProductionTaskSet::factory()->for($fixture['workspace'])->create(['name' => 'Default set']);
    ProductionTaskSetItem::factory()->for($defaultSet, 'taskSet')->for($taskType, 'taskType')->create([
        'position' => 1,
        'days_after_production' => 0,
    ]);
    $defaultSet->recipes()->attach($fixture['recipe']->id, ['is_default' => true]);
    $explicitSet = ProductionTaskSet::factory()->for($fixture['workspace'])->create(['name' => 'Explicit set']);
    ProductionTaskSetItem::factory()->for($explicitSet, 'taskSet')->for($taskType, 'taskType')->create([
        'position' => 1,
        'days_after_production' => 0,
    ]);
    $explicitSet->recipes()->attach($fixture['recipe']->id, ['is_default' => false]);

    $production = app(CreateProductionDraft::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        recipe: $fixture['recipe'],
        basisInputValue: '14',
        basisInputUnit: MassUnit::Kilogram,
        expectedUnits: 100,
        idempotencyKey: 'task-explicit-1',
        taskSet: $explicitSet,
    );

    expect($production->production_task_set_id)->toBe($explicitSet->id);
});

it('leaves the task set null when the product has no default', function (): void {
    $fixture = productionPlanningTask3SoapFixture();

    $production = app(CreateProductionDraft::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        recipe: $fixture['recipe'],
        basisInputValue: '14',
        basisInputUnit: MassUnit::Kilogram,
        expectedUnits: 100,
        idempotencyKey: 'task-null-1',
    );

    expect($production->production_task_set_id)->toBeNull();
});

it('generates tasks from the stored set after the product is archived', function (): void {
    $fixture = productionPlanningTask3SoapFixture();
    $taskType = ProductionTaskType::factory()->for($fixture['workspace'])->create([
        'name' => 'Cure',
        'colour' => '#ff8800',
        'default_duration_minutes' => 45,
    ]);
    $taskSet = ProductionTaskSet::factory()->for($fixture['workspace'])->create(['name' => 'Archived-safe set']);
    ProductionTaskSetItem::factory()->for($taskSet, 'taskSet')->for($taskType, 'taskType')->create([
        'position' => 1,
        'days_after_production' => 0,
    ]);
    ProductionTaskSetItem::factory()->for($taskSet, 'taskSet')->for($taskType, 'taskType')->create([
        'position' => 2,
        'days_after_production' => 2,
    ]);
    $taskSet->recipes()->attach($fixture['recipe']->id, ['is_default' => true]);
    $production = app(CreateProductionDraft::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        recipe: $fixture['recipe'],
        basisInputValue: '14',
        basisInputUnit: MassUnit::Kilogram,
        expectedUnits: 100,
        idempotencyKey: 'task-archived-1',
        plannedFor: '2026-08-20',
    );

    $fixture['recipe']->update(['archived_at' => now()]);

    $generated = app(GenerateProductionTasks::class)->handle($fixture['owner'], $production);
    $task = $generated->tasks()->where('days_after_production', 2)->firstOrFail();

    expect($generated->tasks()->count())->toBe(2)
        ->and($task->name_snapshot)->toBe('Cure')
        ->and($task->colour_snapshot)->toBe('#ff8800')
        ->and($task->duration_minutes)->toBe(45)
        ->and($task->scheduled_for?->toDateString())->toBe('2026-08-24');
});

it('does not discover a task set attached to the product after creation', function (): void {
    $fixture = productionPlanningTask3SoapFixture();
    $production = app(CreateProductionDraft::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        recipe: $fixture['recipe'],
        basisInputValue: '14',
        basisInputUnit: MassUnit::Kilogram,
        expectedUnits: 100,
        idempotencyKey: 'task-late-default-1',
        plannedFor: '2026-08-20',
    );
    $taskType = ProductionTaskType::factory()->for($fixture['workspace'])->create(['name' => 'Cure']);
    $taskSet = ProductionTaskSet::factory()->for($fixture['workspace'])->create(['name' => 'Late default']);
    ProductionTaskSetItem::factory()->for($taskSet, 'taskSet')->for($taskType, 'taskType')->create([
        'position' => 1,
        'days_after_production' => 0,
    ]);
    $taskSet->recipes()->attach($fixture['recipe']->id, ['is_default' => true]);

    $generated = app(GenerateProductionTasks::class)->handle($fixture['owner'], $production);

    expect($generated->production_task_set_id)->toBeNull()
        ->and($generated->tasks()->count())->toBe(0);
});

it('degrades without a product lookup when the stored task set was deleted', function (): void {
    $fixture = productionPlanningTask3SoapFixture();
    $taskType = ProductionTaskType::factory()->for($fixture['workspace'])->create(['name' => 'Cure']);
    $taskSet = ProductionTaskSet::factory()->for($fixture['workspace'])->create(['name' => 'Doomed set']);
    ProductionTaskSetItem::factory()->for($taskSet, 'taskSet')->for($taskType, 'taskType')->create([
        'position' => 1,
        'days_after_production' => 0,
    ]);
    $taskSet->recipes()->attach($fixture['recipe']->id, ['is_default' => true]);
    $production = app(CreateProductionDraft::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        recipe: $fixture['recipe'],
        basisInputValue: '14',
        basisInputUnit: MassUnit::Kilogram,
        expectedUnits: 100,
        idempotencyKey: 'task-deleted-1',
        plannedFor: '2026-08-20',
    );

    $taskSet->delete();

    $generated = app(GenerateProductionTasks::class)->handle($fixture['owner'], $production);

    expect($generated->production_task_set_id)->toBeNull()
        ->and($generated->tasks()->count())->toBe(0);
});

it('deletes draft and scheduled runs without reservations or permanent numbers', function (): void {
    $fixture = productionPlanningTask3SoapFixture();
    $draft = app(CreateProductionDraft::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        recipe: $fixture['recipe'],
        basisInputValue: '14',
        basisInputUnit: MassUnit::Kilogram,
        expectedUnits: 100,
        idempotencyKey: 'delete-draft-1',
    );
    $scheduled = app(CreateProductionDraft::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        recipe: $fixture['recipe'],
        basisInputValue: '14',
        basisInputUnit: MassUnit::Kilogram,
        expectedUnits: 100,
        idempotencyKey: 'delete-scheduled-1',
        status: ProductionRunStatus::Scheduled,
    );

    app(DeleteProductionRun::class)->handle($fixture['owner'], $draft);
    app(DeleteProductionRun::class)->handle($fixture['owner'], $scheduled);

    expect(ProductionRun::query()->find($draft->id))->toBeNull()
        ->and(ProductionRun::query()->find($scheduled->id))->toBeNull()
        ->and(ProductionFormulaLine::query()->count())->toBe(0)
        ->and(ProductionRequirement::query()->count())->toBe(0)
        ->and(ProductionTask::query()->count())->toBe(0);
});

it('rejects deletion once a permanent number, a reservation, or a terminal status exists', function (string $mutate): void {
    $fixture = productionPlanningTask3SoapFixture();
    $production = app(CreateProductionDraft::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        recipe: $fixture['recipe'],
        basisInputValue: '14',
        basisInputUnit: MassUnit::Kilogram,
        expectedUnits: 100,
        idempotencyKey: 'delete-blocked-'.fake()->unique()->numberBetween(1, 99999),
    );

    match ($mutate) {
        'permanent number' => $production->update([
            'batch_number' => 'B-00001',
            'batch_number_serial' => 1,
            'batch_number_assigned_at' => now(),
            'batch_number_assigned_by_user_id' => $fixture['owner']->id,
        ]),
        'reservation' => StockReservation::factory()->create([
            'workspace_id' => $fixture['workspace']->id,
            'production_run_id' => $production->id,
            'production_requirement_id' => $production->requirements()->firstOrFail()->id,
            'stock_lot_id' => StockLot::factory()->released()->for($fixture['workspace'])->create()->id,
            'created_by_user_id' => $fixture['owner']->id,
        ]),
        default => $production->update(['status' => $mutate]),
    };

    expect(function () use ($fixture, $production): void {
        app(DeleteProductionRun::class)->handle($fixture['owner'], $production);
    })->toThrow(ValidationException::class)
        ->and(ProductionRun::query()->find($production->id))->not->toBeNull();
})->with([
    'permanent number' => ['permanent number'],
    'reservation' => ['reservation'],
    'reserved' => [ProductionRunStatus::Reserved->value],
    'in production' => [ProductionRunStatus::InProduction->value],
    'completed' => [ProductionRunStatus::Completed->value],
    'cancelled' => [ProductionRunStatus::Cancelled->value],
    'aborted' => [ProductionRunStatus::Aborted->value],
]);

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
        // Blend-only formulas have no saponified-oils phase, so the production
        // snapshot copies ingredient lines without a lye calculation.
        'manufacturing_mode' => 'blend_only',
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

/**
 * A published soap formula with two saponified oils, one packaging plan, and
 * optional SAP profiles so the production snapshot includes calculated lye.
 *
 * @return array{owner: User, workspace: Workspace, recipe: Recipe, version: RecipeVersion, olive: Ingredient, coconut: Ingredient}
 */
function productionPlanningTask3SoapFixture(bool $withSap = true): array
{
    $fixture = productionPlanningTask2Fixture();
    $fixture['version']->update([
        'manufacturing_mode' => 'saponify_in_formula',
        'calculation_context' => [
            'editing_mode' => 'percentage',
            'lye_type' => 'naoh',
            'koh_purity_percentage' => 90,
            'dual_lye_koh_percentage' => 40,
            'superfat' => 5,
            'oil_weight' => 1000,
            'oil_unit' => 'g',
            'mass_grams' => 1000,
            'totals' => [],
        ],
        'water_settings' => ['mode' => 'percent_of_oils', 'value' => 38],
    ]);

    $oleic = FattyAcid::factory()->create(['key' => 'oleic-'.fake()->unique()->numberBetween(1, 999999), 'name' => 'Oleic']);
    $lauric = FattyAcid::factory()->create(['key' => 'lauric-'.fake()->unique()->numberBetween(1, 999999), 'name' => 'Lauric']);

    $olive = Ingredient::factory()->create(['display_name' => 'Olive oil']);
    $coconut = Ingredient::factory()->create(['display_name' => 'Coconut oil']);

    if ($withSap) {
        IngredientSapProfile::factory()->create(['ingredient_id' => $olive->id, 'koh_sap_value' => 0.188]);
        IngredientFattyAcid::factory()->create([
            'ingredient_id' => $olive->id,
            'fatty_acid_id' => $oleic->id,
            'percentage' => 100,
        ]);
        IngredientSapProfile::factory()->create(['ingredient_id' => $coconut->id, 'koh_sap_value' => 0.19]);
        IngredientFattyAcid::factory()->create([
            'ingredient_id' => $coconut->id,
            'fatty_acid_id' => $lauric->id,
            'percentage' => 100,
        ]);
    }

    $phase = productionPlanningTask2Phase($fixture['version'], $fixture['workspace'], 'Saponified Oils');
    $phase->update(['slug' => 'saponified_oils']);

    RecipeItem::factory()->for($fixture['version'])->for($phase, 'recipePhase')->for($olive)->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $fixture['workspace']->id,
        'workspace_id' => $fixture['workspace']->id,
        'visibility' => Visibility::Private,
        'position' => 1,
        'percentage' => '75.0000',
        'weight' => null,
    ]);
    RecipeItem::factory()->for($fixture['version'])->for($phase, 'recipePhase')->for($coconut)->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $fixture['workspace']->id,
        'workspace_id' => $fixture['workspace']->id,
        'visibility' => Visibility::Private,
        'position' => 2,
        'percentage' => '25.0000',
        'weight' => null,
    ]);

    $packaging = PackagingItem::factory()->for($fixture['workspace'])->create(['name' => 'Soap box']);
    RecipeVersionPackagingItem::query()->create([
        'recipe_version_id' => $fixture['version']->id,
        'packaging_item_id' => $packaging->id,
        'name' => 'Soap box',
        'components_per_unit' => '1.000',
        'position' => 1,
    ]);

    $fixture['olive'] = $olive;
    $fixture['coconut'] = $coconut;

    return $fixture;
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
