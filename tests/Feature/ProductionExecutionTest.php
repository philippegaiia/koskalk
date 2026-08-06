<?php

use App\Actions\Production\AbortProduction;
use App\Actions\Production\AssignProductionBatchNumbers;
use App\Actions\Production\CompleteProduction;
use App\Actions\Production\CreateProductionDraft;
use App\Actions\Production\PrepareProductionStock;
use App\Actions\Production\SaveProductionActuals;
use App\Actions\Production\StartProduction;
use App\Livewire\ProductionBench\Production\ProductionDetail;
use App\MassUnit;
use App\Models\FattyAcid;
use App\Models\Ingredient;
use App\Models\IngredientFattyAcid;
use App\Models\IngredientSapProfile;
use App\Models\PackagingItem;
use App\Models\ProductFamily;
use App\Models\ProductionRun;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\RecipePhase;
use App\Models\RecipeVersion;
use App\Models\RecipeVersionPackagingItem;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\StockReservation;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceProductionEntitlement;
use App\OwnerType;
use App\ProductionConsumptionKind;
use App\ProductionRunStatus;
use App\StockMovementType;
use App\StockReservationStatus;
use App\Visibility;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('records actual consumption during production without posting stock movements', function (): void {
    $fixture = productionExecutionFixture();
    $production = productionExecutionRun($fixture, 'actuals-1');
    $ingredientRequirement = $production->requirements()->where('kind', 'ingredient')->firstOrFail();
    $packagingRequirement = $production->requirements()->where('kind', 'packaging')->firstOrFail();
    $oilLot = StockLot::query()->where('ingredient_id', $fixture['olive']->id)->firstOrFail();
    $packagingLot = StockLot::query()->where('packaging_item_id', $fixture['packaging']->id)->firstOrFail();
    $movementCount = StockMovement::query()->count();

    $saved = app(SaveProductionActuals::class)->handle($fixture['owner'], $production, [
        [
            'production_requirement_id' => $ingredientRequirement->id,
            'stock_lot_id' => $oilLot->id,
            'quantity' => '11000.000000000',
            'note' => 'Slightly over the plan',
        ],
        [
            'production_requirement_id' => $packagingRequirement->id,
            'stock_lot_id' => $packagingLot->id,
            'quantity' => '98',
        ],
    ]);

    $ingredientActual = $saved->consumption()->where('production_requirement_id', $ingredientRequirement->id)->sole();

    expect($saved->status)->toBe(ProductionRunStatus::InProduction)
        ->and($ingredientActual->kind)->toBe(ProductionConsumptionKind::Ingredient)
        ->and($ingredientActual->quantity)->toBe('11000.000000000')
        ->and($ingredientActual->unit_snapshot)->toBe('g')
        ->and($ingredientActual->note)->toBe('Slightly over the plan')
        ->and($ingredientActual->recorded_by_user_id)->toBe($fixture['owner']->id)
        ->and($saved->consumption()->where('production_requirement_id', $packagingRequirement->id)->sole()->quantity)->toBe('98.000000000')
        ->and(StockMovement::query()->count())->toBe($movementCount);
});

it('updates and removes actual rows before the terminal action', function (): void {
    $fixture = productionExecutionFixture();
    $production = productionExecutionRun($fixture, 'actuals-2');
    $ingredientRequirement = $production->requirements()->where('kind', 'ingredient')->firstOrFail();
    $oilLot = StockLot::query()->where('ingredient_id', $fixture['olive']->id)->firstOrFail();

    app(SaveProductionActuals::class)->handle($fixture['owner'], $production, [
        [
            'production_requirement_id' => $ingredientRequirement->id,
            'stock_lot_id' => $oilLot->id,
            'quantity' => '10500.000000000',
        ],
    ]);

    app(SaveProductionActuals::class)->handle($fixture['owner'], $production, [
        [
            'production_requirement_id' => $ingredientRequirement->id,
            'stock_lot_id' => $oilLot->id,
            'quantity' => '9000.000000000',
            'note' => 'Corrected at the bench',
        ],
    ]);

    expect($production->consumption()->sole()->quantity)->toBe('9000.000000000')
        ->and($production->consumption()->sole()->note)->toBe('Corrected at the bench')
        ->and($production->consumption()->count())->toBe(1);

    app(SaveProductionActuals::class)->handle($fixture['owner'], $production, [
        [
            'production_requirement_id' => $ingredientRequirement->id,
            'stock_lot_id' => $oilLot->id,
            'quantity' => '0',
        ],
    ]);

    expect($production->consumption()->count())->toBe(0);
});

it('rejects actual rows outside in-production and invalid quantities', function (): void {
    $fixture = productionExecutionFixture();
    $production = app(CreateProductionDraft::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        recipe: $fixture['recipe'],
        basisInputValue: '14',
        basisInputUnit: MassUnit::Kilogram,
        expectedUnits: 100,
        idempotencyKey: 'actuals-draft-1',
        status: ProductionRunStatus::Scheduled,
        plannedFor: '2026-08-20',
    );

    expect(function () use ($fixture, $production): void {
        app(SaveProductionActuals::class)->handle($fixture['owner'], $production, []);
    })->toThrow(ValidationException::class);

    $started = productionExecutionRun($fixture, 'actuals-3');
    $packagingRequirement = $started->requirements()->where('kind', 'packaging')->firstOrFail();
    $packagingLot = StockLot::query()->where('packaging_item_id', $fixture['packaging']->id)->firstOrFail();

    expect(function () use ($fixture, $started, $packagingRequirement, $packagingLot): void {
        app(SaveProductionActuals::class)->handle($fixture['owner'], $started, [
            [
                'production_requirement_id' => $packagingRequirement->id,
                'stock_lot_id' => $packagingLot->id,
                'quantity' => '2.5',
            ],
        ]);
    })->toThrow(ValidationException::class);

    expect(function () use ($fixture, $started, $packagingRequirement, $packagingLot): void {
        app(SaveProductionActuals::class)->handle($fixture['owner'], $started, [
            [
                'production_requirement_id' => $packagingRequirement->id,
                'stock_lot_id' => $packagingLot->id,
                'quantity' => 'not-a-number',
            ],
        ]);
    })->toThrow(ValidationException::class);

    app(SaveProductionActuals::class)->handle($fixture['owner'], $started, [
        [
            'production_requirement_id' => $packagingRequirement->id,
            'stock_lot_id' => $packagingLot->id,
            'quantity' => '0',
        ],
    ]);

    expect($started->consumption()->count())->toBe(0);
});

it('saves actuals from the production sheet', function (): void {
    $fixture = productionExecutionFixture();
    $production = productionExecutionRun($fixture, 'actuals-ui-1');
    $ingredientRequirement = $production->requirements()->where('kind', 'ingredient')->firstOrFail();
    $oilLot = StockLot::query()->where('ingredient_id', $fixture['olive']->id)->firstOrFail();

    Livewire::actingAs($fixture['owner'])
        ->test(ProductionDetail::class, ['productionId' => (string) $production->id])
        ->assertSee('Actual consumption')
        ->set('actualRows.'.$ingredientRequirement->id.'.stock_lot_id', (string) $oilLot->id)
        ->set('actualRows.'.$ingredientRequirement->id.'.quantity', '10500')
        ->set('actualRows.'.$ingredientRequirement->id.'.note', 'From the bench')
        ->call('saveActuals')
        ->assertDispatched('app-notification', function (string $event, array $payload): bool {
            return $event === 'app-notification'
                && str_starts_with($payload['message'], __('production_bench.production.actuals_saved'))
                && $payload['type'] === 'success';
        });

    expect($production->consumption()->where('production_requirement_id', $ingredientRequirement->id)->sole()->quantity)
        ->toBe('10500.000000000');
});

it('completes a production atomically with consumption, costs, and an output lot', function (): void {
    $fixture = productionExecutionFixture();
    $production = productionExecutionRun($fixture, 'complete-1');
    $ingredientRequirement = $production->requirements()->where('kind', 'ingredient')->firstOrFail();
    $packagingRequirement = $production->requirements()->where('kind', 'packaging')->firstOrFail();
    $oilLot = StockLot::query()->where('ingredient_id', $fixture['olive']->id)->firstOrFail();
    $packagingLot = StockLot::query()->where('packaging_item_id', $fixture['packaging']->id)->firstOrFail();
    $oilLot->update(['historical_unit_cost' => '4.000000000', 'currency' => 'EUR']);
    $packagingLot->update(['historical_unit_cost' => '0.500000000', 'currency' => 'EUR']);

    app(SaveProductionActuals::class)->handle($fixture['owner'], $production, [
        ['production_requirement_id' => $ingredientRequirement->id, 'stock_lot_id' => $oilLot->id, 'quantity' => '11000.000000000', 'note' => 'Over the plan'],
        ['production_requirement_id' => $packagingRequirement->id, 'stock_lot_id' => $packagingLot->id, 'quantity' => '98'],
    ]);

    $completed = app(CompleteProduction::class)->handle(
        actor: $fixture['owner'],
        production: $production->fresh(),
        actualOutputQuantity: '95',
        manufactureDate: '2026-08-20',
    );

    expect($completed->status)->toBe(ProductionRunStatus::Completed)
        ->and($completed->completed_at)->not->toBeNull()
        ->and($completed->completed_by_user_id)->toBe($fixture['owner']->id)
        ->and($completed->manufacture_date?->toDateString())->toBe('2026-08-20')
        ->and($completed->actual_output_units)->toBe(95)
        ->and($completed->actual_output_mass_grams)->toBeNull()
        ->and($completed->actual_ingredient_total)->toBe('44.000000000')
        ->and($completed->actual_packaging_total)->toBe('49.000000000')
        ->and($completed->actual_total_cost)->toBe('93.000000000')
        ->and($completed->cost_currency)->toBe('EUR')
        ->and($completed->actual_cost_per_unit)->toBe('0.978947368');

    // Consumption movements posted (negative deltas on the consumed lots).
    $oilMovement = StockMovement::query()
        ->where('stock_lot_id', $oilLot->id)
        ->where('type', StockMovementType::ProductionConsumption)
        ->sole();
    expect($oilMovement->quantity_delta)->toBe('-11000.000000000')
        ->and($oilMovement->source_id)->toBe($production->id);

    // All reservations released.
    expect(StockReservation::query()->where('production_run_id', $production->id)->where('status', StockReservationStatus::Active)->count())
        ->toBe(0);

    // Output lot coded with the permanent batch number, quarantined.
    $outputLot = $completed->outputLot()->sole();
    expect($outputLot->internal_lot_code)->toBe($completed->batch_number)
        ->and($outputLot->recipe_id)->toBe($fixture['recipe']->id)
        ->and($outputLot->origin->value)->toBe('production_output')
        ->and($outputLot->status->value)->toBe('quarantined')
        ->and($outputLot->unit_kind->value)->toBe('count')
        ->and($outputLot->movements()->where('type', StockMovementType::ProductionOutput)->sole()->quantity_delta)->toBe('95.000000000');

    // Costs immutable: later price changes do not alter the snapshot.
    $oilLot->update(['historical_unit_cost' => '99.000000000']);
    expect($completed->fresh()->actual_ingredient_total)->toBe('44.000000000');
});

it('rejects completion while readiness is missing', function (): void {
    $fixture = productionExecutionFixture();
    $production = productionExecutionRun($fixture, 'complete-readiness-1');
    $packagingRequirement = $production->requirements()->where('kind', 'packaging')->firstOrFail();
    $packagingLot = StockLot::query()->where('packaging_item_id', $fixture['packaging']->id)->firstOrFail();

    // Missing the ingredient actuals.
    app(SaveProductionActuals::class)->handle($fixture['owner'], $production, [
        ['production_requirement_id' => $packagingRequirement->id, 'stock_lot_id' => $packagingLot->id, 'quantity' => '98'],
    ]);

    expect(function () use ($fixture, $production): void {
        app(CompleteProduction::class)->handle(
            actor: $fixture['owner'],
            production: $production->fresh(),
            actualOutputQuantity: '95',
            manufactureDate: '2026-08-20',
        );
    })->toThrow(ValidationException::class);

    // Output quantity required.
    $ingredientRequirement = $production->requirements()->where('kind', 'ingredient')->firstOrFail();
    $oilLot = StockLot::query()->where('ingredient_id', $fixture['olive']->id)->firstOrFail();
    app(SaveProductionActuals::class)->handle($fixture['owner'], $production, [
        ['production_requirement_id' => $ingredientRequirement->id, 'stock_lot_id' => $oilLot->id, 'quantity' => '11000.000000000'],
    ]);

    expect(function () use ($fixture, $production): void {
        app(CompleteProduction::class)->handle(
            actor: $fixture['owner'],
            production: $production->fresh(),
            actualOutputQuantity: '0',
            manufactureDate: '2026-08-20',
        );
    })->toThrow(ValidationException::class);
});

it('rolls back completion atomically when a step fails', function (): void {
    $fixture = productionExecutionFixture();
    $production = productionExecutionRun($fixture, 'complete-rollback-1');
    $ingredientRequirement = $production->requirements()->where('kind', 'ingredient')->firstOrFail();
    $packagingRequirement = $production->requirements()->where('kind', 'packaging')->firstOrFail();
    $oilLot = StockLot::query()->where('ingredient_id', $fixture['olive']->id)->firstOrFail();
    $packagingLot = StockLot::query()->where('packaging_item_id', $fixture['packaging']->id)->firstOrFail();

    app(SaveProductionActuals::class)->handle($fixture['owner'], $production, [
        ['production_requirement_id' => $ingredientRequirement->id, 'stock_lot_id' => $oilLot->id, 'quantity' => '11000.000000000'],
        ['production_requirement_id' => $packagingRequirement->id, 'stock_lot_id' => $packagingLot->id, 'quantity' => '98'],
    ]);

    // Force a failure: a lot already using the permanent batch number as its
    // code makes the output-lot insert violate the unique constraint.
    StockLot::factory()->for($fixture['workspace'])->released()->create([
        'ingredient_id' => $fixture['olive']->id,
        'packaging_item_id' => null,
        'internal_lot_code' => $production->batch_number,
        'unit_kind' => 'mass',
    ]);

    expect(function () use ($fixture, $production): void {
        app(CompleteProduction::class)->handle(
            actor: $fixture['owner'],
            production: $production->fresh(),
            actualOutputQuantity: '95',
            manufactureDate: '2026-08-20',
        );
    })->toThrow(QueryException::class);

    expect($production->fresh()->status)->toBe(ProductionRunStatus::InProduction)
        ->and(StockMovement::query()->where('type', StockMovementType::ProductionConsumption)->count())->toBe(0)
        ->and(StockReservation::query()->where('production_run_id', $production->id)->where('status', StockReservationStatus::Active)->count())->toBe(2)
        ->and(StockLot::query()->where('origin', 'production_output')->count())->toBe(0)
        ->and(StockMovement::query()->where('type', StockMovementType::ProductionOutput)->count())->toBe(0);
});

it('completes intermediate output in grams against an in-house ingredient', function (): void {
    $fixture = productionExecutionFixture();
    $production = productionExecutionRun($fixture, 'complete-intermediate-1');
    $ingredientRequirement = $production->requirements()->where('kind', 'ingredient')->firstOrFail();
    $packagingRequirement = $production->requirements()->where('kind', 'packaging')->firstOrFail();
    $oilLot = StockLot::query()->where('ingredient_id', $fixture['olive']->id)->firstOrFail();
    $packagingLot = StockLot::query()->where('packaging_item_id', $fixture['packaging']->id)->firstOrFail();
    $intermediate = Ingredient::factory()->create(['display_name' => 'Soap base']);

    app(SaveProductionActuals::class)->handle($fixture['owner'], $production, [
        ['production_requirement_id' => $ingredientRequirement->id, 'stock_lot_id' => $oilLot->id, 'quantity' => '11000.000000000'],
        ['production_requirement_id' => $packagingRequirement->id, 'stock_lot_id' => $packagingLot->id, 'quantity' => '98'],
    ]);

    $completed = app(CompleteProduction::class)->handle(
        actor: $fixture['owner'],
        production: $production->fresh(),
        actualOutputQuantity: '12000',
        manufactureDate: '2026-08-20',
        outputIngredientId: $intermediate->id,
    );

    expect($completed->actual_output_mass_grams)->toBe('12000.000000000')
        ->and($completed->actual_output_units)->toBeNull();

    $outputLot = $completed->outputLot()->sole();
    expect($outputLot->ingredient_id)->toBe($intermediate->id)
        ->and($outputLot->recipe_id)->toBeNull()
        ->and($outputLot->unit_kind->value)->toBe('mass')
        ->and($outputLot->movements()->where('type', StockMovementType::ProductionOutput)->sole()->quantity_delta)->toBe('12000.000000000');
});

it('completes a production from the production sheet', function (): void {
    $fixture = productionExecutionFixture();
    $production = productionExecutionRun($fixture, 'complete-ui-1');
    $ingredientRequirement = $production->requirements()->where('kind', 'ingredient')->firstOrFail();
    $packagingRequirement = $production->requirements()->where('kind', 'packaging')->firstOrFail();
    $oilLot = StockLot::query()->where('ingredient_id', $fixture['olive']->id)->firstOrFail();
    $packagingLot = StockLot::query()->where('packaging_item_id', $fixture['packaging']->id)->firstOrFail();

    app(SaveProductionActuals::class)->handle($fixture['owner'], $production, [
        ['production_requirement_id' => $ingredientRequirement->id, 'stock_lot_id' => $oilLot->id, 'quantity' => '11000.000000000'],
        ['production_requirement_id' => $packagingRequirement->id, 'stock_lot_id' => $packagingLot->id, 'quantity' => '98'],
    ]);

    Livewire::actingAs($fixture['owner'])
        ->test(ProductionDetail::class, ['productionId' => (string) $production->id])
        ->assertSee('Complete production')
        ->set('actualOutputQuantity', '95')
        ->set('manufactureDate', '2026-08-20')
        ->call('complete')
        ->assertDispatched('app-notification', function (string $event, array $payload): bool {
            return $event === 'app-notification'
                && str_starts_with($payload['message'], __('production_bench.production.completed'))
                && $payload['type'] === 'success';
        });

    expect($production->fresh()->status)->toBe(ProductionRunStatus::Completed)
        ->and($production->fresh()->outputLot()->sole()->internal_lot_code)->toBe($production->fresh()->batch_number);
});

it('aborts a running production with reconciliation', function (): void {
    $fixture = productionExecutionFixture();
    $production = productionExecutionRun($fixture, 'abort-1');
    $ingredientRequirement = $production->requirements()->where('kind', 'ingredient')->firstOrFail();
    $packagingRequirement = $production->requirements()->where('kind', 'packaging')->firstOrFail();
    $oilLot = StockLot::query()->where('ingredient_id', $fixture['olive']->id)->firstOrFail();
    $packagingLot = StockLot::query()->where('packaging_item_id', $fixture['packaging']->id)->firstOrFail();
    $movementCount = StockMovement::query()->count();

    app(SaveProductionActuals::class)->handle($fixture['owner'], $production, [
        ['production_requirement_id' => $ingredientRequirement->id, 'stock_lot_id' => $oilLot->id, 'quantity' => '4000.000000000'],
        ['production_requirement_id' => $packagingRequirement->id, 'stock_lot_id' => $packagingLot->id, 'quantity' => '30'],
    ]);

    $aborted = app(AbortProduction::class)->handle(
        actor: $fixture['owner'],
        production: $production->fresh(),
        reason: 'The batch seized in the mould',
    );

    expect($aborted->status)->toBe(ProductionRunStatus::Aborted)
        ->and($aborted->aborted_at)->not->toBeNull()
        ->and($aborted->aborted_by_user_id)->toBe($fixture['owner']->id)
        ->and($aborted->abort_reason)->toBe('The batch seized in the mould')
        ->and($aborted->actual_output_units)->toBeNull();

    // Consumption posted for the recorded actuals only.
    expect(StockMovement::query()->count())->toBe($movementCount + 2)
        ->and(StockMovement::query()->where('stock_lot_id', $oilLot->id)->where('type', StockMovementType::ProductionConsumption)->sole()->quantity_delta)
        ->toBe('-4000.000000000');

    // All active reservations released.
    expect(StockReservation::query()->where('production_run_id', $production->id)->where('status', StockReservationStatus::Active)->count())
        ->toBe(0);
});

it('aborts without actuals by releasing reservations only', function (): void {
    $fixture = productionExecutionFixture();
    $production = productionExecutionRun($fixture, 'abort-empty-1');
    $movementCount = StockMovement::query()->count();

    $aborted = app(AbortProduction::class)->handle(
        actor: $fixture['owner'],
        production: $production,
        reason: 'Cancelled before the bench',
    );

    expect($aborted->status)->toBe(ProductionRunStatus::Aborted)
        ->and(StockMovement::query()->count())->toBe($movementCount)
        ->and(StockReservation::query()->where('production_run_id', $production->id)->where('status', StockReservationStatus::Active)->count())
        ->toBe(0);
});

it('rejects aborting outside in-production and rolls back atomically', function (): void {
    $fixture = productionExecutionFixture();
    $production = productionExecutionRun($fixture, 'abort-guard-1');
    $production->update(['status' => ProductionRunStatus::Scheduled]);

    expect(function () use ($fixture, $production): void {
        app(AbortProduction::class)->handle($fixture['owner'], $production, 'Nope');
    })->toThrow(ValidationException::class);

    $running = productionExecutionRun($fixture, 'abort-rollback-1');
    $ingredientRequirement = $running->requirements()->where('kind', 'ingredient')->firstOrFail();
    $oilLot = StockLot::query()->where('ingredient_id', $fixture['olive']->id)->firstOrFail();
    app(SaveProductionActuals::class)->handle($fixture['owner'], $running, [
        ['production_requirement_id' => $ingredientRequirement->id, 'stock_lot_id' => $oilLot->id, 'quantity' => '4000.000000000'],
    ]);
    Schema::drop('stock_movements');

    expect(function () use ($fixture, $running): void {
        app(AbortProduction::class)->handle($fixture['owner'], $running->fresh(), 'Broken');
    })->toThrow(QueryException::class);

    expect($running->fresh()->status)->toBe(ProductionRunStatus::InProduction)
        ->and(StockReservation::query()->where('production_run_id', $running->id)->where('status', StockReservationStatus::Active)->count())
        ->toBe(2);
});

/**
 * @return array{owner: User, workspace: Workspace, recipe: Recipe, version: RecipeVersion, olive: Ingredient, packaging: PackagingItem}
 */
function productionExecutionFixture(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    WorkspaceProductionEntitlement::factory()->for($workspace)->create();
    $family = ProductFamily::factory()->create([
        'slug' => 'execution-soap-'.fake()->unique()->numberBetween(1, 999999),
        'calculation_basis' => 'initial_oils',
    ]);
    $recipe = Recipe::factory()->for($family, 'productFamily')->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Private,
        'name' => 'Execution soap',
    ]);
    $version = RecipeVersion::factory()->for($recipe)->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Private,
        'is_current' => false,
        'manufacturing_mode' => 'saponify_in_formula',
        'batch_mass_grams' => '1000.000000000',
        'batch_unit' => 'g',
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
    $olive = Ingredient::factory()->create(['display_name' => 'Olive oil']);
    IngredientSapProfile::factory()->create(['ingredient_id' => $olive->id, 'koh_sap_value' => 0.188]);
    IngredientFattyAcid::factory()->create([
        'ingredient_id' => $olive->id,
        'fatty_acid_id' => $oleic->id,
        'percentage' => 100,
    ]);

    $phase = RecipePhase::factory()->for($version)->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Private,
        'name' => 'Saponified Oils',
        'slug' => 'saponified_oils',
        'sort_order' => 1,
    ]);
    RecipeItem::factory()->for($version)->for($phase, 'recipePhase')->for($olive)->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Private,
        'position' => 1,
        'percentage' => '100.0000',
        'weight' => null,
    ]);

    $packaging = PackagingItem::factory()->for($workspace)->create(['name' => 'Soap box']);
    RecipeVersionPackagingItem::query()->create([
        'recipe_version_id' => $version->id,
        'packaging_item_id' => $packaging->id,
        'name' => 'Soap box',
        'components_per_unit' => '1.000',
        'position' => 1,
    ]);

    return compact('owner', 'workspace', 'recipe', 'version', 'olive', 'packaging');
}

/**
 * @param  array{owner: User, workspace: Workspace, recipe: Recipe, version: RecipeVersion}  $fixture
 */
function productionExecutionRun(array $fixture, string $idempotencyKey): ProductionRun
{
    $production = app(CreateProductionDraft::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        recipe: $fixture['recipe'],
        basisInputValue: '14',
        basisInputUnit: MassUnit::Kilogram,
        expectedUnits: 100,
        idempotencyKey: $idempotencyKey,
        status: ProductionRunStatus::Scheduled,
        plannedFor: '2026-08-20',
    );

    $production->requirements->each(function ($requirement) use ($fixture): void {
        $lot = StockLot::factory()->for($fixture['workspace'])->released()->create([
            'ingredient_id' => $requirement->ingredient_id,
            'packaging_item_id' => $requirement->packaging_item_id,
            'unit_kind' => $requirement->ingredient_id !== null ? 'mass' : 'count',
            'expires_at' => '2027-01-01',
            'released_at' => now(),
        ]);
        StockMovement::factory()->for($lot, 'stockLot')->create([
            'workspace_id' => $fixture['workspace']->id,
            'type' => StockMovementType::OpeningBalance,
            'quantity_delta' => $requirement->ingredient_id !== null
                ? $requirement->required_mass_grams
                : (string) $requirement->required_units,
        ]);
    });

    app(PrepareProductionStock::class)->handle(
        actor: $fixture['owner'],
        productionIds: [$production->id],
        idempotencyKey: $idempotencyKey.'-prepare',
    );

    app(AssignProductionBatchNumbers::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        productionIds: [$production->id],
    );

    return app(StartProduction::class)->handle($fixture['owner'], $production->fresh());
}
