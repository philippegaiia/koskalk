<?php

use App\Actions\Production\AbortProduction;
use App\Actions\Production\AssignProductionBatchNumbers;
use App\Actions\Production\CompleteProduction;
use App\Actions\Production\CompleteProductionTask;
use App\Actions\Production\CreateProductionDraft;
use App\Actions\Production\DeleteProductionRun;
use App\Actions\Production\IssueFinishedGoods;
use App\Actions\Production\PrepareProductionStock;
use App\Actions\Production\ReleaseOutputLot;
use App\Actions\Production\ReleaseProductionStock;
use App\Actions\Production\SaveProductionActuals;
use App\Actions\Production\SaveProductionJournalEntry;
use App\Actions\Production\StartProduction;
use App\Actions\Purchasing\ReceiveDirectGoodsReceipt;
use App\Enums\ListingPriceBasis;
use App\Enums\MassUnit;
use App\Enums\OwnerType;
use App\Enums\ProductionConsumptionKind;
use App\Enums\ProductionDocumentType;
use App\Enums\ProductionFormulaComponent;
use App\Enums\ProductionOutputType;
use App\Enums\ProductionRunStatus;
use App\Enums\StockLotOrigin;
use App\Enums\StockLotStatus;
use App\Enums\StockMovementType;
use App\Enums\StockReservationStatus;
use App\Enums\StockUnitKind;
use App\Enums\Visibility;
use App\Enums\WorkspaceMemberRole;
use App\Livewire\ProductionBench\Production\ProductionDetail;
use App\Livewire\ProductionBench\Production\ProductionIndex;
use App\Models\FattyAcid;
use App\Models\Ingredient;
use App\Models\IngredientFattyAcid;
use App\Models\IngredientSapProfile;
use App\Models\InterfaceTranslation;
use App\Models\PackagingItem;
use App\Models\ProductFamily;
use App\Models\ProductionRun;
use App\Models\ProductionTask;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\RecipePhase;
use App\Models\RecipeVersion;
use App\Models\RecipeVersionPackagingItem;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\StockReservation;
use App\Models\Supplier;
use App\Models\SupplierListing;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Models\WorkspaceProductionEntitlement;
use App\Support\NumberLocale;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('defaults water actuals at start and saves them atomically with lot actuals', function (): void {
    $fixture = productionExecutionFixture();
    $production = productionExecutionRun($fixture, 'water-actuals-1', start: false);
    $waterLine = $production->formulaLines()->where('component', ProductionFormulaComponent::Water)->firstOrFail();
    $ingredientRequirement = $production->requirements()->where('ingredient_id', $fixture['olive']->id)->firstOrFail();
    $packagingRequirement = $production->requirements()->where('kind', 'packaging')->firstOrFail();
    $oilLot = StockLot::query()->where('ingredient_id', $fixture['olive']->id)->firstOrFail();
    $packagingLot = StockLot::query()->where('packaging_item_id', $fixture['packaging']->id)->firstOrFail();
    $movementCount = StockMovement::query()->count();

    $started = app(StartProduction::class)->handle($fixture['owner'], $production);

    expect($started->formulaLines()->whereKey($waterLine->id)->firstOrFail()->actual_mass_grams)
        ->toBe($waterLine->planned_mass_grams)
        ->and(StockMovement::query()->count())->toBe($movementCount);

    expect(fn () => app(SaveProductionActuals::class)->handle(
        actor: $fixture['owner'],
        production: $started,
        rows: [[
            'production_requirement_id' => $ingredientRequirement->id,
            'stock_lot_id' => $oilLot->id,
            'quantity' => '11000',
        ]],
        calculatedRows: [[
            'production_formula_line_id' => $waterLine->id,
            'actual_mass_grams' => '0',
        ]],
    ))->toThrow(ValidationException::class);

    expect($started->fresh()->consumption()->count())->toBe(0)
        ->and($started->fresh()->formulaLines()->whereKey($waterLine->id)->firstOrFail()->actual_mass_grams)
        ->toBe($waterLine->planned_mass_grams);

    $saved = app(SaveProductionActuals::class)->handle(
        actor: $fixture['owner'],
        production: $started,
        rows: [
            [
                'production_requirement_id' => $ingredientRequirement->id,
                'stock_lot_id' => $oilLot->id,
                'quantity' => '11000',
            ],
            [
                'production_requirement_id' => $packagingRequirement->id,
                'stock_lot_id' => $packagingLot->id,
                'quantity' => '98',
            ],
        ],
        calculatedRows: [[
            'production_formula_line_id' => $waterLine->id,
            'actual_mass_grams' => '1540.5',
        ]],
    );

    expect($saved->formulaLines()->whereKey($waterLine->id)->firstOrFail()->actual_mass_grams)
        ->toBe('1540.500000000')
        ->and($saved->consumption()->count())->toBe(2)
        ->and(StockMovement::query()->where('type', StockMovementType::ProductionConsumption)->count())
        ->toBe(0);
});

it('does not start production with a reserved lot that is no longer consumable', function (array $changes): void {
    $this->travelTo('2026-08-10 08:00:00');

    $fixture = productionExecutionFixture();
    $production = productionExecutionRun($fixture, 'start-lot-eligibility-'.fake()->uuid(), start: false);
    $lot = StockLot::query()->where('ingredient_id', $fixture['olive']->id)->firstOrFail();
    $waterLine = $production->formulaLines()->where('component', ProductionFormulaComponent::Water)->firstOrFail();
    $lot->update($changes);

    expect(fn () => app(StartProduction::class)->handle($fixture['owner'], $production))
        ->toThrow(ValidationException::class)
        ->and($production->fresh()->status)->toBe(ProductionRunStatus::Reserved)
        ->and($production->fresh()->started_at)->toBeNull()
        ->and($waterLine->fresh()->actual_mass_grams)->toBeNull();
})->with([
    'quarantined' => [['status' => StockLotStatus::Quarantined]],
    'not yet available' => [['available_from' => '2026-08-11']],
    'expired' => [['expires_at' => '2026-08-09']],
]);

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

it('rejects actual consumption from quarantined or not-yet-available lots', function (): void {
    $this->travelTo('2026-08-10 08:00:00');

    $quarantinedFixture = productionExecutionFixture();
    $quarantinedProduction = productionExecutionRun($quarantinedFixture, 'actuals-eligibility-quarantine');
    $quarantinedRequirement = $quarantinedProduction->requirements()->where('kind', 'ingredient')->firstOrFail();
    $quarantinedLot = StockLot::query()->where('ingredient_id', $quarantinedFixture['olive']->id)->firstOrFail();
    $quarantinedLot->update(['status' => StockLotStatus::Quarantined]);

    expect(fn () => app(SaveProductionActuals::class)->handle($quarantinedFixture['owner'], $quarantinedProduction, [
        [
            'production_requirement_id' => $quarantinedRequirement->id,
            'stock_lot_id' => $quarantinedLot->id,
            'quantity' => '1',
        ],
    ]))->toThrow(ValidationException::class);

    $futureFixture = productionExecutionFixture();
    $futureProduction = productionExecutionRun($futureFixture, 'actuals-eligibility-future');
    $futureRequirement = $futureProduction->requirements()->where('kind', 'ingredient')->firstOrFail();
    $futureLot = StockLot::query()->where('ingredient_id', $futureFixture['olive']->id)->firstOrFail();
    $futureLot->update(['available_from' => '2026-08-21']);

    expect(fn () => app(SaveProductionActuals::class)->handle($futureFixture['owner'], $futureProduction, [
        [
            'production_requirement_id' => $futureRequirement->id,
            'stock_lot_id' => $futureLot->id,
            'quantity' => '1',
        ],
    ]))->toThrow(ValidationException::class);
});

it('accepts an actual lot available on the execution date even when it expires before the planned date', function (): void {
    $this->travelTo('2026-08-10 08:00:00');

    $fixture = productionExecutionFixture();
    $production = productionExecutionRun($fixture, 'actuals-execution-date-expiry');
    $requirement = $production->requirements()->where('kind', 'ingredient')->firstOrFail();
    $lot = StockLot::query()->where('ingredient_id', $fixture['olive']->id)->firstOrFail();
    $lot->update(['expires_at' => '2026-08-12']);

    $saved = app(SaveProductionActuals::class)->handle($fixture['owner'], $production, [[
        'production_requirement_id' => $requirement->id,
        'stock_lot_id' => $lot->id,
        'quantity' => '1',
    ]]);

    expect($saved->consumption()->where('stock_lot_id', $lot->id)->exists())->toBeTrue();
});

it('rejects an actual lot that is unavailable on the current execution date', function (): void {
    $this->travelTo('2026-08-10 08:00:00');

    $fixture = productionExecutionFixture();
    $production = productionExecutionRun($fixture, 'actuals-execution-date-availability');
    $requirement = $production->requirements()->where('kind', 'ingredient')->firstOrFail();
    $lot = StockLot::query()->where('ingredient_id', $fixture['olive']->id)->firstOrFail();
    $lot->update(['available_from' => '2026-08-11']);

    expect(fn () => app(SaveProductionActuals::class)->handle($fixture['owner'], $production, [[
        'production_requirement_id' => $requirement->id,
        'stock_lot_id' => $lot->id,
        'quantity' => '1',
    ]]))->toThrow(ValidationException::class);
});

it('rechecks actual lots against the confirmed manufacture date before posting movements', function (): void {
    $this->travelTo('2026-08-10 08:00:00');

    $fixture = productionExecutionFixture();
    $production = productionExecutionRun($fixture, 'actuals-manufacture-date-recheck');
    $ingredientRequirement = $production->requirements()->where('kind', 'ingredient')->firstOrFail();
    $packagingRequirement = $production->requirements()->where('kind', 'packaging')->firstOrFail();
    $ingredientLot = StockLot::query()->where('ingredient_id', $fixture['olive']->id)->firstOrFail();
    $packagingLot = StockLot::query()->where('packaging_item_id', $fixture['packaging']->id)->firstOrFail();
    $ingredientLot->update(['expires_at' => '2026-08-22']);

    app(SaveProductionActuals::class)->handle($fixture['owner'], $production, [
        [
            'production_requirement_id' => $ingredientRequirement->id,
            'stock_lot_id' => $ingredientLot->id,
            'quantity' => '11000',
        ],
        [
            'production_requirement_id' => $packagingRequirement->id,
            'stock_lot_id' => $packagingLot->id,
            'quantity' => '98',
        ],
    ]);

    $movementCount = StockMovement::query()->count();
    $outputLotCount = StockLot::query()
        ->where('production_run_id', $production->id)
        ->where('origin', StockLotOrigin::ProductionOutput)
        ->count();

    expect(fn () => app(CompleteProduction::class)->handle(
        actor: $fixture['owner'],
        production: $production->fresh(),
        actualOutputQuantity: '95',
        manufactureDate: '2026-08-25',
    ))->toThrow(ValidationException::class);

    expect(StockMovement::query()->count())->toBe($movementCount)
        ->and(StockLot::query()
            ->where('production_run_id', $production->id)
            ->where('origin', StockLotOrigin::ProductionOutput)
            ->count())->toBe($outputLotCount);
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

    // A positive quantity without a lot is rejected with a clear message.
    expect(function () use ($fixture, $started, $packagingRequirement): void {
        app(SaveProductionActuals::class)->handle($fixture['owner'], $started, [
            [
                'production_requirement_id' => $packagingRequirement->id,
                'stock_lot_id' => null,
                'quantity' => '5',
            ],
        ]);
    })->toThrow(ValidationException::class);
});

it('returns the catalogued localized message when actuals are saved outside production', function (string $locale): void {
    $messages = productionExecutionCatalogueText('actuals_running_only');
    InterfaceTranslation::query()->firstOrCreate([
        'group' => 'production_bench',
        'key' => 'production.validation.actuals_running_only',
    ], [
        'text' => $messages,
    ]);
    app()->setLocale($locale);

    $fixture = productionExecutionFixture();
    $production = app(CreateProductionDraft::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        recipe: $fixture['recipe'],
        basisInputValue: '14',
        basisInputUnit: MassUnit::Kilogram,
        expectedUnits: 100,
        idempotencyKey: "localized-actuals-{$locale}",
        status: ProductionRunStatus::Scheduled,
        plannedFor: '2026-08-20',
    );

    try {
        app(SaveProductionActuals::class)->handle($fixture['owner'], $production, []);
    } catch (ValidationException $exception) {
        expect($exception->errors()['production'])->toBe([$messages[$locale]]);

        return;
    }

    $this->fail('Expected saving actuals outside production to fail.');
})->with([
    'German' => ['de'],
    'Spanish' => ['es'],
    'French' => ['fr'],
    'Italian' => ['it'],
    'Dutch' => ['nl'],
]);

it('saves actuals from the production sheet', function (): void {
    $fixture = productionExecutionFixture();
    $production = productionExecutionRun($fixture, 'actuals-ui-1');
    $ingredientRequirement = $production->requirements()->where('kind', 'ingredient')->firstOrFail();
    $oilLot = StockLot::query()->where('ingredient_id', $fixture['olive']->id)->firstOrFail();

    Livewire::actingAs($fixture['owner'])
        ->test(ProductionDetail::class, ['productionId' => (string) $production->id])
        ->assertSee(__('production_bench.production.actual_used'))
        ->set('actualRows.'.$ingredientRequirement->id.'.stock_lot_id', (string) $oilLot->id)
        ->set('actualRows.'.$ingredientRequirement->id.'.quantity', '10500')
        ->set('actualRows.'.$ingredientRequirement->id.'.note', 'From the bench')
        ->call('saveActuals')
        ->assertHasNoErrors()
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
    $oilLot->update(['historical_unit_cost' => '0.012500000', 'currency' => 'EUR']);
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
        ->and($completed->actual_ingredient_total)->toBe('137.500000000')
        ->and($completed->actual_packaging_total)->toBe('49.000000000')
        ->and($completed->actual_total_cost)->toBe('186.500000000')
        ->and($completed->cost_currency)->toBe('EUR')
        ->and($completed->actual_cost_per_unit)->toBe('1.963157894');

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

    Livewire::actingAs($fixture['owner'])
        ->test(ProductionDetail::class, ['productionId' => (string) $completed->id])
        ->assertSee(__('production_bench.production.output_planned'))
        ->assertSee('100 unit')
        ->assertSee(__('production_bench.production.output_actual'))
        ->assertSee('95 unit')
        ->assertSee('-5.00%');

    // Costs immutable: later price changes do not alter the snapshot.
    $oilLot->update(['historical_unit_cost' => '99.000000000']);
    expect($completed->fresh()->actual_ingredient_total)->toBe('137.500000000');
});

it('rechecks source lot eligibility before posting completion consumption', function (): void {
    $fixture = productionExecutionFixture();
    $production = productionExecutionRun($fixture, 'complete-lot-eligibility-1');
    $ingredientRequirement = $production->requirements()->where('kind', 'ingredient')->firstOrFail();
    $packagingRequirement = $production->requirements()->where('kind', 'packaging')->firstOrFail();
    $oilLot = StockLot::query()->where('ingredient_id', $fixture['olive']->id)->firstOrFail();
    $packagingLot = StockLot::query()->where('packaging_item_id', $fixture['packaging']->id)->firstOrFail();

    app(SaveProductionActuals::class)->handle($fixture['owner'], $production, [
        ['production_requirement_id' => $ingredientRequirement->id, 'stock_lot_id' => $oilLot->id, 'quantity' => '11000'],
        ['production_requirement_id' => $packagingRequirement->id, 'stock_lot_id' => $packagingLot->id, 'quantity' => '98'],
    ]);

    $oilLot->update(['status' => StockLotStatus::Quarantined]);

    expect(fn () => app(CompleteProduction::class)->handle(
        actor: $fixture['owner'],
        production: $production->fresh(),
        actualOutputQuantity: '95',
        manufactureDate: '2026-08-20',
    ))->toThrow(ValidationException::class);

    expect($production->fresh()->status)->toBe(ProductionRunStatus::InProduction)
        ->and(StockMovement::query()->where('type', StockMovementType::ProductionConsumption)->count())->toBe(0)
        ->and(StockLot::query()->where('origin', StockLotOrigin::ProductionOutput)->count())->toBe(0);
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
        ->and(StockReservation::query()->where('production_run_id', $production->id)->where('status', StockReservationStatus::Active)->count())->toBe(3)
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
    $production->update([
        'production_output_type' => ProductionOutputType::ManufacturedIngredient,
        'output_ingredient_id' => $intermediate->id,
    ]);

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

it('completes configured manufactured output from the production snapshot', function (): void {
    $fixture = productionExecutionFixture();
    $firstOutput = Ingredient::factory()->for($fixture['workspace'])->create([
        'display_name' => 'Turmeric oil macerate',
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $fixture['workspace']->id,
        'visibility' => Visibility::Private,
        'is_manufactured' => true,
        'requires_admin_review' => false,
    ]);
    $secondOutput = Ingredient::factory()->for($fixture['workspace'])->create([
        'display_name' => 'Lavender oil macerate',
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $fixture['workspace']->id,
        'visibility' => Visibility::Private,
        'is_manufactured' => true,
        'requires_admin_review' => false,
    ]);
    $fixture['recipe']->update([
        'production_output_type' => ProductionOutputType::ManufacturedIngredient,
        'output_ingredient_id' => $firstOutput->id,
        'ready_delay_days' => 4,
    ]);

    $production = productionExecutionRun($fixture, 'complete-configured-intermediate-1');

    expect($production->production_output_type)->toBe(ProductionOutputType::ManufacturedIngredient)
        ->and($production->output_ingredient_id)->toBe($firstOutput->id)
        ->and($production->output_ready_delay_days)->toBe(4)
        ->and($production->estimated_ready_on?->toDateString())->toBe('2026-08-24');

    $fixture['recipe']->update([
        'output_ingredient_id' => $secondOutput->id,
        'ready_delay_days' => 30,
    ]);

    $ingredientRequirement = $production->requirements()->where('kind', 'ingredient')->firstOrFail();
    $packagingRequirement = $production->requirements()->where('kind', 'packaging')->firstOrFail();
    $oilLot = StockLot::query()->where('ingredient_id', $fixture['olive']->id)->firstOrFail();
    $packagingLot = StockLot::query()->where('packaging_item_id', $fixture['packaging']->id)->firstOrFail();
    app(SaveProductionActuals::class)->handle($fixture['owner'], $production, [
        ['production_requirement_id' => $ingredientRequirement->id, 'stock_lot_id' => $oilLot->id, 'quantity' => '11000.000000000'],
        ['production_requirement_id' => $packagingRequirement->id, 'stock_lot_id' => $packagingLot->id, 'quantity' => '98'],
    ]);

    $completed = app(CompleteProduction::class)->handle(
        actor: $fixture['owner'],
        production: $production->fresh(),
        actualOutputQuantity: '12000',
        manufactureDate: '2026-08-20',
        estimatedReadyOn: '2026-08-29',
    );

    $outputLot = $completed->outputLot()->sole();

    expect($outputLot->ingredient_id)->toBe($firstOutput->id)
        ->and($outputLot->recipe_id)->toBeNull()
        ->and($outputLot->unit_kind->value)->toBe('mass')
        ->and($outputLot->status)->toBe(StockLotStatus::Quarantined)
        ->and($outputLot->estimated_ready_on?->toDateString())->toBe('2026-08-29')
        ->and($outputLot->available_from)->toBeNull();
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
        ->toBe(3);
});

it('releases a quarantined output lot and issues finished goods', function (): void {
    $fixture = productionExecutionFixture();
    $production = productionExecutionRun($fixture, 'output-1');
    $ingredientRequirement = $production->requirements()->where('kind', 'ingredient')->firstOrFail();
    $packagingRequirement = $production->requirements()->where('kind', 'packaging')->firstOrFail();
    $oilLot = StockLot::query()->where('ingredient_id', $fixture['olive']->id)->firstOrFail();
    $packagingLot = StockLot::query()->where('packaging_item_id', $fixture['packaging']->id)->firstOrFail();
    app(SaveProductionActuals::class)->handle($fixture['owner'], $production, [
        ['production_requirement_id' => $ingredientRequirement->id, 'stock_lot_id' => $oilLot->id, 'quantity' => '11000.000000000'],
        ['production_requirement_id' => $packagingRequirement->id, 'stock_lot_id' => $packagingLot->id, 'quantity' => '98'],
    ]);
    $completed = app(CompleteProduction::class)->handle(
        actor: $fixture['owner'],
        production: $production->fresh(),
        actualOutputQuantity: '95',
        manufactureDate: '2026-08-20',
    );
    $outputLot = $completed->outputLot()->sole();

    // Quarantined: not available for issue.
    expect($outputLot->status->value)->toBe('quarantined');

    expect(function () use ($fixture, $outputLot): void {
        app(IssueFinishedGoods::class)->handle($fixture['owner'], $outputLot, StockMovementType::Sample, '1');
    })->toThrow(ValidationException::class);

    // Release before the estimate requires explicit confirmation.
    $outputLot->update(['estimated_ready_on' => now()->addDays(28)->toDateString()]);
    expect(function () use ($fixture, $outputLot): void {
        app(ReleaseOutputLot::class)->handle($fixture['owner'], $outputLot);
    })->toThrow(ValidationException::class);

    $released = app(ReleaseOutputLot::class)->handle(
        actor: $fixture['owner'],
        lot: $outputLot,
        note: 'Cured and packed',
        earlyReleaseConfirmed: true,
    );

    expect($released->status->value)->toBe('released')
        ->and($released->released_at)->not->toBeNull()
        ->and($released->released_by_user_id)->toBe($fixture['owner']->id)
        ->and($released->release_note)->toBe('Cured and packed');

    // Issue movements post against the released lot.
    $issued = app(IssueFinishedGoods::class)->handle(
        actor: $fixture['owner'],
        outputLot: $released,
        kind: StockMovementType::Shipment,
        quantity: '10',
        note: 'First customer order',
    );
    app(IssueFinishedGoods::class)->handle($fixture['owner'], $issued, StockMovementType::Sample, '2');
    app(IssueFinishedGoods::class)->handle($fixture['owner'], $issued, StockMovementType::Damaged, '1');
    app(IssueFinishedGoods::class)->handle($fixture['owner'], $issued, StockMovementType::InternalUse, '3');

    expect($issued->movements()->where('type', StockMovementType::Shipment)->sole()->quantity_delta)->toBe('-10.000000000')
        ->and($issued->movements()->where('type', StockMovementType::Sample)->sole()->quantity_delta)->toBe('-2.000000000')
        ->and($issued->movements()->where('type', StockMovementType::Damaged)->sole()->quantity_delta)->toBe('-1.000000000')
        ->and($issued->movements()->where('type', StockMovementType::InternalUse)->sole()->quantity_delta)->toBe('-3.000000000');

    // Over-issue rejected.
    expect(function () use ($fixture, $issued): void {
        app(IssueFinishedGoods::class)->handle($fixture['owner'], $issued, StockMovementType::Shipment, '999');
    })->toThrow(ValidationException::class);
});

it('requires all production tasks to be complete before releasing output', function (): void {
    $fixture = productionExecutionFixture();
    $production = productionExecutionRun($fixture, 'output-task-gate-1');
    $ingredientRequirement = $production->requirements()->where('kind', 'ingredient')->firstOrFail();
    $packagingRequirement = $production->requirements()->where('kind', 'packaging')->firstOrFail();
    $oilLot = StockLot::query()->where('ingredient_id', $fixture['olive']->id)->firstOrFail();
    $packagingLot = StockLot::query()->where('packaging_item_id', $fixture['packaging']->id)->firstOrFail();
    app(SaveProductionActuals::class)->handle($fixture['owner'], $production, [
        ['production_requirement_id' => $ingredientRequirement->id, 'stock_lot_id' => $oilLot->id, 'quantity' => '11000'],
        ['production_requirement_id' => $packagingRequirement->id, 'stock_lot_id' => $packagingLot->id, 'quantity' => '98'],
    ]);
    $completed = app(CompleteProduction::class)->handle(
        actor: $fixture['owner'],
        production: $production->fresh(),
        actualOutputQuantity: '95',
        manufactureDate: '2026-08-20',
    );
    $task = ProductionTask::factory()->for($fixture['workspace'])->for($completed, 'productionRun')->create([
        'name_snapshot' => 'Final quality check',
        'scheduled_for' => '2026-08-20',
        'completed_at' => null,
    ]);
    $outputLot = $completed->outputLot()->sole();
    $outputLot->update(['estimated_ready_on' => now()->subDay()->toDateString()]);

    expect(fn () => app(ReleaseOutputLot::class)->handle($fixture['owner'], $outputLot))
        ->toThrow(ValidationException::class);

    app(CompleteProductionTask::class)->handle($fixture['owner'], $task);

    expect(app(ReleaseOutputLot::class)->handle($fixture['owner'], $outputLot)->status)
        ->toBe(StockLotStatus::Released);
});

it('records journal entries during planning and production, read-only afterwards', function (): void {
    $fixture = productionExecutionFixture();
    $production = productionExecutionRun($fixture, 'journal-1');

    $saved = app(SaveProductionJournalEntry::class)->handle($fixture['owner'], $production, 'Mixed at 40°C, batter traced after 8 minutes.');

    expect($saved->journalEntries()->count())->toBe(1)
        ->and($saved->journalEntries()->sole()->body)->toBe('Mixed at 40°C, batter traced after 8 minutes.')
        ->and($saved->journalEntries()->sole()->created_by_user_id)->toBe($fixture['owner']->id);

    app(SaveProductionJournalEntry::class)->handle($fixture['owner'], $production, 'Added lavender at trace.');

    expect($production->journalEntries()->count())->toBe(2)
        ->and($production->journalEntries()->first()->body)->toBe('Mixed at 40°C, batter traced after 8 minutes.');

    $ingredientRequirement = $production->requirements()->where('kind', 'ingredient')->firstOrFail();
    $packagingRequirement = $production->requirements()->where('kind', 'packaging')->firstOrFail();
    $oilLot = StockLot::query()->where('ingredient_id', $fixture['olive']->id)->firstOrFail();
    $packagingLot = StockLot::query()->where('packaging_item_id', $fixture['packaging']->id)->firstOrFail();
    app(SaveProductionActuals::class)->handle($fixture['owner'], $production, [
        ['production_requirement_id' => $ingredientRequirement->id, 'stock_lot_id' => $oilLot->id, 'quantity' => '11000.000000000'],
        ['production_requirement_id' => $packagingRequirement->id, 'stock_lot_id' => $packagingLot->id, 'quantity' => '98'],
    ]);
    $completed = app(CompleteProduction::class)->handle(
        actor: $fixture['owner'],
        production: $production->fresh(),
        actualOutputQuantity: '95',
        manufactureDate: '2026-08-20',
    );

    expect(function () use ($fixture, $completed): void {
        app(SaveProductionJournalEntry::class)->handle($fixture['owner'], $completed, 'Too late to add.');
    })->toThrow(ValidationException::class);
});

it('prepares stock partially and completes coverage on a later pass', function (): void {
    $fixture = productionExecutionFixture();
    $production = app(CreateProductionDraft::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        recipe: $fixture['recipe'],
        basisInputValue: '14',
        basisInputUnit: MassUnit::Kilogram,
        expectedUnits: 100,
        idempotencyKey: 'partial-prepare-1',
        status: ProductionRunStatus::Scheduled,
        plannedFor: '2026-08-20',
    );
    $ingredientRequirement = $production->requirements()->where('kind', 'ingredient')->firstOrFail();
    $packagingRequirement = $production->requirements()->where('kind', 'packaging')->firstOrFail();
    $lyeRequirement = $production->requirements()
        ->where('ingredient_id', Ingredient::query()->where('catalog_key', 'CH1')->sole()->id)
        ->firstOrFail();
    $oilLot = StockLot::factory()->for($fixture['workspace'])->released()->create([
        'ingredient_id' => $fixture['olive']->id,
        'packaging_item_id' => null,
        'unit_kind' => 'mass',
        'expires_at' => '2027-01-01',
        'released_at' => now(),
    ]);
    StockMovement::factory()->for($oilLot, 'stockLot')->create([
        'workspace_id' => $fixture['workspace']->id,
        'type' => StockMovementType::OpeningBalance,
        'quantity_delta' => '5000.000000000',
    ]);
    $packagingLot = StockLot::factory()->for($fixture['workspace'])->released()->create([
        'ingredient_id' => null,
        'packaging_item_id' => $fixture['packaging']->id,
        'unit_kind' => 'count',
        'expires_at' => '2027-01-01',
        'released_at' => now(),
    ]);
    StockMovement::factory()->for($packagingLot, 'stockLot')->create([
        'workspace_id' => $fixture['workspace']->id,
        'type' => StockMovementType::OpeningBalance,
        'quantity_delta' => '100',
    ]);
    $lyeLot = StockLot::factory()->for($fixture['workspace'])->released()->create([
        'ingredient_id' => $lyeRequirement->ingredient_id,
        'packaging_item_id' => null,
        'unit_kind' => 'mass',
        'expires_at' => '2027-01-01',
        'released_at' => now(),
    ]);
    StockMovement::factory()->for($lyeLot, 'stockLot')->create([
        'workspace_id' => $fixture['workspace']->id,
        'type' => StockMovementType::OpeningBalance,
        'quantity_delta' => (string) $lyeRequirement->required_mass_grams,
    ]);

    // Only 5000 g of the 14000 g oil requirement is available: partial.
    $prepared = app(PrepareProductionStock::class)->handle(
        actor: $fixture['owner'],
        productionIds: [$production->id],
        idempotencyKey: 'partial-prepare-confirm',
    );

    expect($prepared[0]->status)->toBe(ProductionRunStatus::Scheduled)
        ->and(StockReservation::query()->where('production_requirement_id', $ingredientRequirement->id)->sum('quantity'))->toEqual(5000)
        ->and(StockReservation::query()->where('production_requirement_id', $packagingRequirement->id)->sum('quantity'))->toEqual(100);

    // A later pass with the full stock completes coverage.
    StockMovement::factory()->for($oilLot, 'stockLot')->create([
        'workspace_id' => $fixture['workspace']->id,
        'type' => StockMovementType::OpeningBalance,
        'quantity_delta' => '9000.000000000',
    ]);
    $completed = app(PrepareProductionStock::class)->handle(
        actor: $fixture['owner'],
        productionIds: [$production->id],
        idempotencyKey: 'partial-prepare-complete',
    );

    expect($completed[0]->status)->toBe(ProductionRunStatus::Reserved)
        ->and(StockReservation::query()->where('production_requirement_id', $ingredientRequirement->id)->sum('quantity'))->toEqual(14000);
});

it('shows the release stock control for a scheduled run with partial reservations and allows deletion after release', function (): void {
    $fixture = productionExecutionFixture();
    $production = app(CreateProductionDraft::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        recipe: $fixture['recipe'],
        basisInputValue: '14',
        basisInputUnit: MassUnit::Kilogram,
        expectedUnits: 100,
        idempotencyKey: 'scheduled-release-ui-1',
        status: ProductionRunStatus::Scheduled,
        plannedFor: '2026-08-20',
    );
    $oilLot = StockLot::factory()->for($fixture['workspace'])->released()->create([
        'ingredient_id' => $fixture['olive']->id,
        'packaging_item_id' => null,
        'unit_kind' => 'mass',
        'expires_at' => '2027-01-01',
        'released_at' => now(),
    ]);
    StockMovement::factory()->for($oilLot, 'stockLot')->create([
        'workspace_id' => $fixture['workspace']->id,
        'type' => StockMovementType::OpeningBalance,
        'quantity_delta' => '5000.000000000',
    ]);
    $packagingLot = StockLot::factory()->for($fixture['workspace'])->released()->create([
        'ingredient_id' => null,
        'packaging_item_id' => $fixture['packaging']->id,
        'unit_kind' => 'count',
        'expires_at' => '2027-01-01',
        'released_at' => now(),
    ]);
    StockMovement::factory()->for($packagingLot, 'stockLot')->create([
        'workspace_id' => $fixture['workspace']->id,
        'type' => StockMovementType::OpeningBalance,
        'quantity_delta' => '100',
    ]);
    $lyeRequirement = $production->requirements()
        ->where('ingredient_id', Ingredient::query()->where('catalog_key', 'CH1')->sole()->id)
        ->firstOrFail();
    $lyeLot = StockLot::factory()->for($fixture['workspace'])->released()->create([
        'ingredient_id' => $lyeRequirement->ingredient_id,
        'packaging_item_id' => null,
        'unit_kind' => 'mass',
        'expires_at' => '2027-01-01',
        'released_at' => now(),
    ]);
    StockMovement::factory()->for($lyeLot, 'stockLot')->create([
        'workspace_id' => $fixture['workspace']->id,
        'type' => StockMovementType::OpeningBalance,
        'quantity_delta' => (string) $lyeRequirement->required_mass_grams,
    ]);

    // Partial preparation: only 5000 g of the 14000 g oil requirement is
    // available, so the run stays scheduled with active reservations.
    $prepared = app(PrepareProductionStock::class)->handle(
        actor: $fixture['owner'],
        productionIds: [$production->id],
        idempotencyKey: 'scheduled-release-ui-prepare',
    );

    expect($prepared[0]->status)->toBe(ProductionRunStatus::Scheduled)
        ->and(StockReservation::query()->where('production_run_id', $production->id)->where('status', StockReservationStatus::Active)->count())->toBe(3);

    // Deletion is blocked while reservations exist…
    expect(fn () => app(DeleteProductionRun::class)->handle($fixture['owner'], $production->fresh()))
        ->toThrow(ValidationException::class);

    // …but the detail page offers the release control on a scheduled run.
    Livewire::actingAs($fixture['owner'])->test(ProductionDetail::class, ['productionId' => $production->id])
        ->assertSee(__('production_bench.production.release_stock'))
        ->call('releaseStock')
        ->assertHasNoErrors()
        ->assertDispatched('production-stock-released');

    expect(StockReservation::query()->where('production_run_id', $production->id)->where('status', StockReservationStatus::Active)->count())->toBe(0);

    // After release the run can be deleted.
    app(DeleteProductionRun::class)->handle($fixture['owner'], $production->fresh());

    expect(ProductionRun::query()->find($production->id))->toBeNull();
});

it('releases reservations per requirement and returns to scheduled when empty', function (): void {
    $fixture = productionExecutionFixture();
    $production = productionExecutionRun($fixture, 'partial-release-1', start: false);
    $ingredientRequirement = $production->requirements()->where('kind', 'ingredient')->firstOrFail();
    $packagingRequirement = $production->requirements()->where('kind', 'packaging')->firstOrFail();
    $lyeRequirement = $production->requirements()
        ->where('ingredient_id', Ingredient::query()->where('catalog_key', 'CH1')->sole()->id)
        ->firstOrFail();

    $released = app(ReleaseProductionStock::class)->handle(
        actor: $fixture['owner'],
        production: $production,
        productionRequirementId: $ingredientRequirement->id,
    );

    expect($released->status)->toBe(ProductionRunStatus::Scheduled)
        ->and(StockReservation::query()->where('production_requirement_id', $ingredientRequirement->id)->where('status', StockReservationStatus::Active)->count())->toBe(0)
        ->and(StockReservation::query()->where('production_requirement_id', $packagingRequirement->id)->where('status', StockReservationStatus::Active)->count())->toBe(1);

    $emptied = app(ReleaseProductionStock::class)->handle(
        actor: $fixture['owner'],
        production: $released,
        productionRequirementId: $packagingRequirement->id,
    );

    $fullyReleased = app(ReleaseProductionStock::class)->handle(
        actor: $fixture['owner'],
        production: $emptied,
        productionRequirementId: $lyeRequirement->id,
    );

    expect($fullyReleased->status)->toBe(ProductionRunStatus::Scheduled)
        ->and(StockReservation::query()->where('production_run_id', $production->id)->where('status', StockReservationStatus::Active)->count())->toBe(0);
});

it('shows lifecycle sections appropriate to the run status', function (): void {
    $fixture = productionExecutionFixture();
    $reserved = productionExecutionRun($fixture, 'ui-lifecycle-1', start: false);

    Livewire::actingAs($fixture['owner'])
        ->test(ProductionDetail::class, ['productionId' => (string) $reserved->id])
        ->assertSee('Start production')
        ->assertSee('Release stock')
        ->assertDontSee('Actual consumption')
        ->assertDontSee('Complete production');

    $started = app(StartProduction::class)->handle($fixture['owner'], $reserved->fresh());

    Livewire::actingAs($fixture['owner'])
        ->test(ProductionDetail::class, ['productionId' => (string) $started->id])
        ->assertSee(__('production_bench.production.actual_used'))
        ->assertSee('Complete production')
        ->assertSee('Abort production')
        ->assertSee('Production journal');
});

it('requires explicit confirmation before starting ahead of the planned date', function (): void {
    $this->travelTo('2026-08-10 10:00:00');
    $fixture = productionExecutionFixture();
    $reserved = productionExecutionRun($fixture, 'early-start-ui-1', start: false);

    $page = Livewire::actingAs($fixture['owner'])
        ->test(ProductionDetail::class, ['productionId' => (string) $reserved->id])
        ->call('start')
        ->assertDispatched('early-start-confirmation-requested', function (string $event, array $payload): bool {
            return $event === 'early-start-confirmation-requested'
                && $payload['plannedFor'] === '2026-08-20'
                && $payload['message'] === __('production_bench.production.early_start_confirm', [
                    'date' => '2026-08-20',
                ]);
        });

    expect($reserved->fresh()->status)->toBe(ProductionRunStatus::Reserved);

    $page->call('confirmEarlyStart')
        ->assertHasNoErrors()
        ->assertDispatched('production-started');

    expect($reserved->fresh()->status)->toBe(ProductionRunStatus::InProduction);
});

it('renders one workflow table with formula, lye, water, packaging, and lifecycle landmarks', function (): void {
    $fixture = productionExecutionFixture();
    $reserved = productionExecutionRun($fixture, 'workflow-page-contract-1', start: false);

    $html = Livewire::actingAs($fixture['owner'])
        ->test(ProductionDetail::class, ['productionId' => (string) $reserved->id])
        ->html();

    expect(substr_count($html, 'data-testid="production-header"'))->toBe(1)
        ->and(substr_count($html, 'data-testid="production-lifecycle"'))->toBe(1)
        ->and(substr_count($html, 'data-testid="primary-production-action"'))->toBe(1)
        ->and(substr_count($html, 'data-testid="batch-materials-table"'))->toBe(1)
        ->and(str_contains($html, 'data-testid="requirements-detail-heading"'))->toBeFalse()
        ->and(str_contains($html, 'Sodium hydroxide'))->toBeTrue()
        ->and(str_contains($html, 'Water'))->toBeTrue()
        ->and(str_contains($html, 'Olive oil'))->toBeTrue()
        ->and(str_contains($html, 'Soap box'))->toBeTrue()
        ->and(str_contains($html, __('production_bench.production.actuals_not_stock_tracked')))->toBeTrue()
        ->and(str_contains($html, __('production_bench.production.reserved_lots')))->toBeTrue();
});

it('prices actual consumption at the received per-gram cost from a real receipt', function (): void {
    $fixture = productionExecutionFixture();
    $supplier = Supplier::factory()->for($fixture['workspace'])->create();
    $listing = SupplierListing::factory()
        ->for($fixture['workspace'])
        ->for($supplier)
        ->for($fixture['olive'])
        ->create([
            'unit_kind' => StockUnitKind::Mass,
            'net_quantity' => '5',
            'net_unit' => 'kg',
            'canonical_quantity_per_purchase_format' => '5000',
        ]);
    $receipt = app(ReceiveDirectGoodsReceipt::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        supplier: $supplier,
        idempotencyKey: 'receipt-cost-1',
        lines: [[
            'listing' => $listing,
            'packs_received' => 1,
            'actual_quantity' => '4.8',
            'actual_unit' => 'kg',
            'receipt_price_basis' => ListingPriceBasis::PerUnit,
            'receipt_price_amount' => '12.5',
            'receipt_price_unit' => 'kg',
            'currency' => 'EUR',
        ]],
        receivedAt: '2026-08-03',
    );
    $oilLot = $receipt->lines->sole()->stockLot;

    // The receipt flow prices by the listing net quantity (€12.5/kg × 5 kg
    // pack = €62.50), giving 62.50 / 4,800 g = 0.013020833 per gram.
    expect($oilLot->historical_unit_cost)->toBe('0.013020833');

    $production = productionExecutionRun($fixture, 'receipt-cost-prod-1');
    $ingredientRequirement = $production->requirements()->where('kind', 'ingredient')->firstOrFail();
    $packagingRequirement = $production->requirements()->where('kind', 'packaging')->firstOrFail();
    $packagingLot = StockLot::query()->where('packaging_item_id', $fixture['packaging']->id)->firstOrFail();
    $packagingLot->update(['historical_unit_cost' => '0.500000000', 'currency' => 'EUR']);

    app(SaveProductionActuals::class)->handle($fixture['owner'], $production, [
        ['production_requirement_id' => $ingredientRequirement->id, 'stock_lot_id' => $oilLot->id, 'quantity' => '4000.000000000'],
        ['production_requirement_id' => $packagingRequirement->id, 'stock_lot_id' => $packagingLot->id, 'quantity' => '98'],
    ]);

    $completed = app(CompleteProduction::class)->handle(
        actor: $fixture['owner'],
        production: $production->fresh(),
        actualOutputQuantity: '95',
        manufactureDate: '2026-08-20',
    );

    // 4,000 g × €0.013020833/g = €52.083332 — not €0.052.
    expect($completed->actual_ingredient_total)->toBe('52.083332000')
        ->and($completed->actual_total_cost)->toBe('101.083332000')
        ->and($completed->cost_currency)->toBe('EUR');
});

it('formats production numbers with the user workspace number locale', function (): void {
    $fixture = productionExecutionFixture();
    $production = productionExecutionRun($fixture, 'format-locale-1', start: false);
    $production->update([
        'basis_input_value' => '1234.500000000',
        'expected_units' => 1234,
    ]);
    $fixture['owner']->update(['number_locale' => 'fr']);

    $formattedBasis = NumberLocale::formatAdaptiveDecimal('1234.5', 0, 3, 'fr');
    $formattedUnits = NumberLocale::formatDecimal(1234, 0, 'fr');

    Livewire::actingAs($fixture['owner'])
        ->test(ProductionDetail::class, ['productionId' => (string) $production->id])
        ->assertSee($formattedBasis)
        ->assertSee($formattedUnits)
        ->assertDontSee('1234.500000000');

    Livewire::actingAs($fixture['owner'])
        ->test(ProductionIndex::class)
        ->assertSee($formattedBasis)
        ->assertSee($formattedUnits);
});

it('prices an intermediate output lot per gram and propagates it downstream', function (): void {
    // Run A produces the intermediate.
    $fixtureA = productionExecutionFixture();
    $intermediate = Ingredient::factory()->create(['display_name' => 'Soap base']);
    $runA = productionExecutionRun($fixtureA, 'inter-a-1');
    $runA->update([
        'production_output_type' => ProductionOutputType::ManufacturedIngredient,
        'output_ingredient_id' => $intermediate->id,
    ]);
    $oilLotA = StockLot::query()->where('ingredient_id', $fixtureA['olive']->id)->firstOrFail();
    $packagingLotA = StockLot::query()->where('packaging_item_id', $fixtureA['packaging']->id)->firstOrFail();
    $oilLotA->update(['historical_unit_cost' => '0.012500000', 'currency' => 'EUR']);
    $packagingLotA->update(['historical_unit_cost' => '0.500000000', 'currency' => 'EUR']);
    $ingredientRequirementA = $runA->requirements()->where('kind', 'ingredient')->firstOrFail();
    $packagingRequirementA = $runA->requirements()->where('kind', 'packaging')->firstOrFail();
    app(SaveProductionActuals::class)->handle($fixtureA['owner'], $runA, [
        ['production_requirement_id' => $ingredientRequirementA->id, 'stock_lot_id' => $oilLotA->id, 'quantity' => '11000.000000000'],
        ['production_requirement_id' => $packagingRequirementA->id, 'stock_lot_id' => $packagingLotA->id, 'quantity' => '98'],
    ]);
    $completedA = app(CompleteProduction::class)->handle(
        actor: $fixtureA['owner'],
        production: $runA->fresh(),
        actualOutputQuantity: '12000',
        manufactureDate: '2026-08-20',
        outputIngredientId: $intermediate->id,
    );
    $intermediateLot = $completedA->outputLot()->sole();

    // €137.50 ingredients + €49.00 packaging / 12,000 g = €0.015541666 per gram.
    expect($intermediateLot->historical_unit_cost)->toBe('0.015541666')
        ->and($intermediateLot->currency)->toBe('EUR')
        ->and($intermediateLot->costing_currency)->toBe('EUR');

    // Manufactured output must be explicitly released and ready before it
    // can be consumed by a downstream production.
    $intermediateLot->update(['estimated_ready_on' => now()->subDay()->toDateString()]);
    $intermediateLot = app(ReleaseOutputLot::class)->handle($fixtureA['owner'], $intermediateLot);

    // Run B consumes the intermediate in the same workspace and prices it
    // from run A.
    $fixtureB = productionExecutionFixture($intermediate, $fixtureA['workspace'], $fixtureA['owner']);
    $runB = productionExecutionRun($fixtureB, 'inter-b-1');
    $ingredientRequirementB = $runB->requirements()->where('kind', 'ingredient')->firstOrFail();
    $packagingRequirementB = $runB->requirements()->where('kind', 'packaging')->firstOrFail();
    $packagingLotB = StockLot::query()->where('packaging_item_id', $fixtureB['packaging']->id)->firstOrFail();
    app(SaveProductionActuals::class)->handle($fixtureB['owner'], $runB, [
        ['production_requirement_id' => $ingredientRequirementB->id, 'stock_lot_id' => $intermediateLot->id, 'quantity' => '6000.000000000'],
        ['production_requirement_id' => $packagingRequirementB->id, 'stock_lot_id' => $packagingLotB->id, 'quantity' => '50'],
    ]);
    $completedB = app(CompleteProduction::class)->handle(
        actor: $fixtureB['owner'],
        production: $runB->fresh(),
        actualOutputQuantity: '60',
        manufactureDate: '2026-08-22',
    );

    // 6,000 g × €0.015541666 = €93.249996 — never a silent zero.
    expect($completedB->actual_ingredient_total)->toBe('93.249996000')
        ->and($completedB->cost_currency)->toBe('EUR');
});

it('shows saved actuals after a page reload instead of reservation defaults', function (): void {
    $fixture = productionExecutionFixture();
    $production = productionExecutionRun($fixture, 'actuals-reload-1');
    $ingredientRequirement = $production->requirements()->where('kind', 'ingredient')->firstOrFail();
    $packagingRequirement = $production->requirements()->where('kind', 'packaging')->firstOrFail();
    $oilLot = StockLot::query()->where('ingredient_id', $fixture['olive']->id)->firstOrFail();
    $packagingLot = StockLot::query()->where('packaging_item_id', $fixture['packaging']->id)->firstOrFail();

    app(SaveProductionActuals::class)->handle($fixture['owner'], $production, [
        ['production_requirement_id' => $ingredientRequirement->id, 'stock_lot_id' => $oilLot->id, 'quantity' => '9000.000000000', 'note' => 'From the bench'],
        ['production_requirement_id' => $packagingRequirement->id, 'stock_lot_id' => $packagingLot->id, 'quantity' => '80'],
    ]);

    // Fresh mount (reload) must load the saved actuals, not the defaults.
    Livewire::actingAs($fixture['owner'])
        ->test(ProductionDetail::class, ['productionId' => (string) $production->id])
        ->assertSet('actualRows.'.$ingredientRequirement->id.'-'.$oilLot->id.'.quantity', '9000.000000000')
        ->assertSet('actualRows.'.$ingredientRequirement->id.'-'.$oilLot->id.'.note', 'From the bench')
        ->assertSet('actualRows.'.$packagingRequirement->id.'-'.$packagingLot->id.'.quantity', '80.000000000');
});

it('records and completes actuals from two lots of the same ingredient', function (): void {
    $fixture = productionExecutionFixture();
    $production = productionExecutionRun($fixture, 'multi-lot-1');
    $ingredientRequirement = $production->requirements()->where('kind', 'ingredient')->firstOrFail();
    $packagingRequirement = $production->requirements()->where('kind', 'packaging')->firstOrFail();
    $firstLot = StockLot::query()->where('ingredient_id', $fixture['olive']->id)->orderBy('id')->firstOrFail();
    $secondLot = StockLot::factory()->for($fixture['workspace'])->released()->create([
        'ingredient_id' => $fixture['olive']->id,
        'packaging_item_id' => null,
        'unit_kind' => 'mass',
        'expires_at' => '2027-01-01',
        'released_at' => now(),
    ]);
    StockMovement::factory()->for($secondLot, 'stockLot')->create([
        'workspace_id' => $fixture['workspace']->id,
        'type' => StockMovementType::OpeningBalance,
        'quantity_delta' => '6000.000000000',
    ]);
    StockReservation::factory()->create([
        'workspace_id' => $fixture['workspace']->id,
        'production_run_id' => $production->id,
        'production_requirement_id' => $ingredientRequirement->id,
        'stock_lot_id' => $secondLot->id,
        'quantity' => '6000.000000000',
        'created_by_user_id' => $fixture['owner']->id,
    ]);
    $packagingLot = StockLot::query()->where('packaging_item_id', $fixture['packaging']->id)->firstOrFail();

    $firstKey = $ingredientRequirement->id.'-'.$firstLot->id;
    $secondKey = $ingredientRequirement->id.'-'.$secondLot->id;

    // The sheet shows one row per lot with both lot codes.
    Livewire::actingAs($fixture['owner'])
        ->test(ProductionDetail::class, ['productionId' => (string) $production->id])
        ->assertSee($firstLot->internal_lot_code)
        ->assertSee($secondLot->internal_lot_code)
        ->set('actualRows.'.$firstKey.'.quantity', '8000')
        ->set('actualRows.'.$secondKey.'.quantity', '6000')
        ->set('actualRows.'.($packagingRequirement->id.'-'.$packagingLot->id).'.quantity', '98')
        ->call('saveActuals');

    expect($production->consumption()->where('production_requirement_id', $ingredientRequirement->id)->count())->toBe(2)
        ->and($production->consumption()->where('stock_lot_id', $firstLot->id)->sole()->quantity)->toBe('8000.000000000')
        ->and($production->consumption()->where('stock_lot_id', $secondLot->id)->sole()->quantity)->toBe('6000.000000000');

    $completed = app(CompleteProduction::class)->handle(
        actor: $fixture['owner'],
        production: $production->fresh(),
        actualOutputQuantity: '95',
        manufactureDate: '2026-08-20',
    );

    expect(StockMovement::query()->where('type', StockMovementType::ProductionConsumption)->where('stock_lot_id', $firstLot->id)->sole()->quantity_delta)
        ->toBe('-8000.000000000')
        ->and(StockMovement::query()->where('type', StockMovementType::ProductionConsumption)->where('stock_lot_id', $secondLot->id)->sole()->quantity_delta)
        ->toBe('-6000.000000000')
        ->and($completed->status)->toBe(ProductionRunStatus::Completed);
});

it('rejects completion when lots resolve to mixed currencies', function (): void {
    $fixture = productionExecutionFixture();
    $production = productionExecutionRun($fixture, 'mixed-currency-1');
    $ingredientRequirement = $production->requirements()->where('kind', 'ingredient')->firstOrFail();
    $packagingRequirement = $production->requirements()->where('kind', 'packaging')->firstOrFail();
    $oilLot = StockLot::query()->where('ingredient_id', $fixture['olive']->id)->firstOrFail();
    $packagingLot = StockLot::query()->where('packaging_item_id', $fixture['packaging']->id)->firstOrFail();

    // Oil lot: workspace-converted costing values in EUR.
    $oilLot->update([
        'historical_unit_cost' => '0.012500000',
        'currency' => 'USD',
        'costing_unit_cost' => '0.011000000',
        'costing_currency' => 'EUR',
    ]);
    // Packaging lot: only source-currency historical values in USD (no
    // workspace costing values) — mixing with EUR.
    $packagingLot->update([
        'historical_unit_cost' => '0.500000000',
        'currency' => 'USD',
        'costing_unit_cost' => null,
        'costing_currency' => null,
    ]);

    app(SaveProductionActuals::class)->handle($fixture['owner'], $production, [
        ['production_requirement_id' => $ingredientRequirement->id, 'stock_lot_id' => $oilLot->id, 'quantity' => '11000.000000000'],
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
});

it('subtracts downstream reservations when issuing an intermediate lot and rejects foreign lots', function (): void {
    $fixture = productionExecutionFixture();
    $production = productionExecutionRun($fixture, 'issue-inter-1');
    $intermediate = Ingredient::factory()->create(['display_name' => 'Soap base']);
    $production->update([
        'production_output_type' => ProductionOutputType::ManufacturedIngredient,
        'output_ingredient_id' => $intermediate->id,
    ]);
    $ingredientRequirement = $production->requirements()->where('kind', 'ingredient')->firstOrFail();
    $packagingRequirement = $production->requirements()->where('kind', 'packaging')->firstOrFail();
    $oilLot = StockLot::query()->where('ingredient_id', $fixture['olive']->id)->firstOrFail();
    $packagingLot = StockLot::query()->where('packaging_item_id', $fixture['packaging']->id)->firstOrFail();
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
    $completed->outputLot()->sole()->update(['estimated_ready_on' => now()->subDay()->toDateString()]);
    $intermediateLot = app(ReleaseOutputLot::class)->handle($fixture['owner'], $completed->outputLot()->sole());

    // A later production reserves 4,000 g of the 12,000 g intermediate.
    StockReservation::factory()->create([
        'workspace_id' => $fixture['workspace']->id,
        'production_run_id' => $production->id,
        'production_requirement_id' => $ingredientRequirement->id,
        'stock_lot_id' => $intermediateLot->id,
        'quantity' => '4000.000000000',
        'created_by_user_id' => $fixture['owner']->id,
    ]);

    // 12,000 physical − 4,000 reserved = 8,000 issuable.
    app(IssueFinishedGoods::class)->handle($fixture['owner'], $intermediateLot, StockMovementType::Shipment, '8000');

    expect(function () use ($fixture, $intermediateLot): void {
        app(IssueFinishedGoods::class)->handle($fixture['owner'], $intermediateLot, StockMovementType::Shipment, '1');
    })->toThrow(ValidationException::class);

    // A non-output lot (opening balance) cannot be released or issued.
    $openingLot = StockLot::factory()->for($fixture['workspace'])->released()->create([
        'ingredient_id' => $fixture['olive']->id,
        'packaging_item_id' => null,
        'unit_kind' => 'mass',
        'origin' => 'opening_balance',
    ]);

    expect(function () use ($fixture, $openingLot): void {
        app(ReleaseOutputLot::class)->handle($fixture['owner'], $openingLot);
    })->toThrow(ValidationException::class)
        ->and(fn (): StockLot => app(IssueFinishedGoods::class)->handle($fixture['owner'], $openingLot, StockMovementType::Sample, '1'))
        ->toThrow(ValidationException::class);
});

it('rejects fractional issue quantities for finished count lots', function (): void {
    $fixture = productionExecutionFixture();
    $production = productionExecutionRun($fixture, 'issue-count-1');
    $ingredientRequirement = $production->requirements()->where('kind', 'ingredient')->firstOrFail();
    $packagingRequirement = $production->requirements()->where('kind', 'packaging')->firstOrFail();
    $oilLot = StockLot::query()->where('ingredient_id', $fixture['olive']->id)->firstOrFail();
    $packagingLot = StockLot::query()->where('packaging_item_id', $fixture['packaging']->id)->firstOrFail();
    app(SaveProductionActuals::class)->handle($fixture['owner'], $production, [
        ['production_requirement_id' => $ingredientRequirement->id, 'stock_lot_id' => $oilLot->id, 'quantity' => '11000.000000000'],
        ['production_requirement_id' => $packagingRequirement->id, 'stock_lot_id' => $packagingLot->id, 'quantity' => '98'],
    ]);
    $completed = app(CompleteProduction::class)->handle(
        actor: $fixture['owner'],
        production: $production->fresh(),
        actualOutputQuantity: '95',
        manufactureDate: '2026-08-20',
    );
    $completed->outputLot()->sole()->update(['estimated_ready_on' => now()->subDay()->toDateString()]);
    $finishedLot = app(ReleaseOutputLot::class)->handle($fixture['owner'], $completed->outputLot()->sole());

    expect(function () use ($fixture, $finishedLot): void {
        app(IssueFinishedGoods::class)->handle($fixture['owner'], $finishedLot, StockMovementType::Shipment, '2.5');
    })->toThrow(ValidationException::class);
});

it('disables mutation controls for viewer-role members', function (): void {
    $fixture = productionExecutionFixture();
    $production = productionExecutionRun($fixture, 'viewer-1', start: false);
    $viewer = User::factory()->create();
    WorkspaceMember::factory()->for($fixture['workspace'])->for($viewer)->create([
        'role' => WorkspaceMemberRole::Viewer,
    ]);

    $page = Livewire::actingAs($viewer)
        ->test(ProductionDetail::class, ['productionId' => (string) $production->id]);

    // Start, release, complete, and journal controls exist but are disabled.
    $page->assertSee('Start production')
        ->assertSee('Release stock')
        ->assertSeeHtml('wire:click="start"')
        ->assertSeeHtml('disabled');

    $html = $page->html();
    preg_match('/wire:click="start"[^>]*/', $html, $m);
    expect($m[0] ?? '')->toContain('disabled');
});

it('shows a live readiness checklist before completion', function (): void {
    $fixture = productionExecutionFixture();
    $production = productionExecutionRun($fixture, 'readiness-1');
    $ingredientRequirement = $production->requirements()->where('kind', 'ingredient')->firstOrFail();
    $packagingRequirement = $production->requirements()->where('kind', 'packaging')->firstOrFail();
    $lyeRequirement = $production->requirements()
        ->where('ingredient_id', Ingredient::query()->where('catalog_key', 'CH1')->sole()->id)
        ->firstOrFail();
    $oilLot = StockLot::query()->where('ingredient_id', $fixture['olive']->id)->firstOrFail();
    $packagingLot = StockLot::query()->where('packaging_item_id', $fixture['packaging']->id)->firstOrFail();
    $lyeLot = StockLot::query()->where('ingredient_id', $lyeRequirement->ingredient_id)->firstOrFail();

    // Nothing recorded: the checklist names the missing requirement.
    $page = Livewire::actingAs($fixture['owner'])
        ->test(ProductionDetail::class, ['productionId' => (string) $production->id]);

    $page->assertSee('Ready to complete?')
        ->assertSee(__('production_bench.production.readiness_actuals').': '.$ingredientRequirement->subject_name_snapshot)
        ->assertSee(__('production_bench.production.readiness_output'));

    // Fill everything: the readiness messages clear.
    app(SaveProductionActuals::class)->handle($fixture['owner'], $production, [
        ['production_requirement_id' => $ingredientRequirement->id, 'stock_lot_id' => $oilLot->id, 'quantity' => '11000.000000000'],
        ['production_requirement_id' => $packagingRequirement->id, 'stock_lot_id' => $packagingLot->id, 'quantity' => '98'],
        ['production_requirement_id' => $lyeRequirement->id, 'stock_lot_id' => $lyeLot->id, 'quantity' => (string) $lyeRequirement->required_mass_grams],
    ]);

    $page = Livewire::actingAs($fixture['owner'])
        ->test(ProductionDetail::class, ['productionId' => (string) $production->id])
        ->set('actualOutputQuantity', '95')
        ->set('manufactureDate', '2026-08-20');

    $html = $page->html();

    expect(str_contains($html, __('production_bench.production.readiness_actuals').':'))->toBeFalse()
        ->and(str_contains($html, __('production_bench.production.readiness_coverage').':'))->toBeFalse()
        ->and(str_contains($html, __('production_bench.production.readiness_output').'✗'))->toBeFalse()
        ->and(str_contains($html, __('production_bench.production.readiness_date').'✗'))->toBeFalse();
});

it('attaches a private journal document to the production', function (): void {
    Storage::fake(config('media.disk'));
    $fixture = productionExecutionFixture();
    $production = productionExecutionRun($fixture, 'journal-doc-1');

    Livewire::actingAs($fixture['owner'])
        ->test(ProductionDetail::class, ['productionId' => (string) $production->id])
        ->set('journalDocumentUpload', UploadedFile::fake()->image('batch-photo.jpg'))
        ->set('journalDocumentNote', 'Mould filled at 09:15')
        ->call('attachJournalDocument')
        ->assertDispatched('app-notification', function (string $event, array $payload): bool {
            return $event === 'app-notification'
                && str_starts_with($payload['message'], __('production_bench.production.journal_document_attached'))
                && $payload['type'] === 'success';
        });

    $document = $production->documents()->where('type', ProductionDocumentType::Journal)->sole();

    expect($document->note)->toBe('Mould filled at 09:15')
        ->and($document->mediaAsset->workspace_id)->toBe($fixture['workspace']->id)
        ->and($document->mediaAsset->original_filename)->toBe('batch-photo.jpg');
});

it('sets family and task based ready dates on output lots', function (): void {
    $completeRun = function (array $fixture, string $key, array $runOverrides = [], array $tasks = []): ProductionRun {
        $production = productionExecutionRun($fixture, $key);
        $production->update($runOverrides);
        $ingredientRequirement = $production->requirements()->where('kind', 'ingredient')->firstOrFail();
        $packagingRequirement = $production->requirements()->where('kind', 'packaging')->firstOrFail();
        $oilLot = StockLot::query()->where('ingredient_id', $fixture['olive']->id)->firstOrFail();
        $packagingLot = StockLot::query()->where('packaging_item_id', $fixture['packaging']->id)->firstOrFail();
        app(SaveProductionActuals::class)->handle($fixture['owner'], $production, [
            ['production_requirement_id' => $ingredientRequirement->id, 'stock_lot_id' => $oilLot->id, 'quantity' => '11000.000000000'],
            ['production_requirement_id' => $packagingRequirement->id, 'stock_lot_id' => $packagingLot->id, 'quantity' => '98'],
        ]);

        foreach ($tasks as $taskDate) {
            ProductionTask::factory()->for($production, 'productionRun')->create([
                'workspace_id' => $fixture['workspace']->id,
                'name_snapshot' => 'Cure',
                'scheduled_for' => $taskDate,
            ]);
        }

        return app(CompleteProduction::class)->handle(
            actor: $fixture['owner'],
            production: $production->fresh(),
            actualOutputQuantity: '95',
            manufactureDate: '2026-08-20',
        );
    };

    $fixture = productionExecutionFixture();

    // Soap, no tasks: +21 days after manufacture.
    $soap = $completeRun($fixture, 'ready-soap-1');
    expect($soap->outputLot()->sole()->estimated_ready_on?->toDateString())->toBe('2026-09-10');

    // A later run snapshot is unaffected by changing formula metadata after planning.
    $cosmetic = $completeRun($fixture, 'ready-cosmetic-1', [
        'formula_context_snapshot' => ['calculation_basis' => 'total_formula'],
        'basis_kind' => 'total_formula_mass',
    ]);
    expect($cosmetic->outputLot()->sole()->estimated_ready_on?->toDateString())->toBe('2026-09-10');

    // Tasks do not override the snapshot delay; they are a separate release gate.
    $tasked = $completeRun($fixture, 'ready-task-1', [], ['2026-08-25', '2026-09-01']);
    expect($tasked->outputLot()->sole()->estimated_ready_on?->toDateString())->toBe('2026-09-10');
});

it('requires the manufacture date before completing from the sheet', function (): void {
    $fixture = productionExecutionFixture();
    $production = productionExecutionRun($fixture, 'complete-no-date-1');
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
        ->set('actualOutputQuantity', '95')
        ->call('complete')
        ->assertHasErrors('manufacture_date');

    expect($production->fresh()->status)->toBe(ProductionRunStatus::InProduction);
});

/**
 * @return array{owner: User, workspace: Workspace, recipe: Recipe, version: RecipeVersion, olive: Ingredient, packaging: PackagingItem}
 */
function productionExecutionFixture(?Ingredient $oil = null, ?Workspace $workspace = null, ?User $owner = null): array
{
    $owner ??= User::factory()->create();
    $workspace ??= Workspace::factory()->for($owner, 'owner')->create();
    WorkspaceProductionEntitlement::query()->firstOrCreate(['workspace_id' => $workspace->id], [
        'status' => 'active',
        'activated_at' => now(),
    ]);
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

    Ingredient::query()->withoutGlobalScopes()->firstOrCreate([
        'catalog_key' => 'CH1',
    ], [
        'display_name' => 'Sodium hydroxide',
    ]);
    Ingredient::query()->withoutGlobalScopes()->firstOrCreate([
        'catalog_key' => 'CH3',
    ], [
        'display_name' => 'Potassium hydroxide',
    ]);

    $oleic = FattyAcid::factory()->create(['key' => 'oleic-'.fake()->unique()->numberBetween(1, 999999), 'name' => 'Oleic']);
    $olive = $oil ?? Ingredient::factory()->create(['display_name' => 'Olive oil']);
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
function productionExecutionRun(array $fixture, string $idempotencyKey, bool $start = true): ProductionRun
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

    if (! $start) {
        return $production->fresh();
    }

    return app(StartProduction::class)->handle($fixture['owner'], $production->fresh());
}

/** @return array<string, string> */
function productionExecutionCatalogueText(string $key): array
{
    $translation = collect(File::json(database_path('seeders/data/interface-translations.json'))['translations'])
        ->first(fn (array $row): bool => $row['group'] === 'production_bench'
            && $row['key'] === "production.validation.{$key}");

    if (! is_array($translation) || ! is_array($translation['text'] ?? null)) {
        throw new LogicException("Missing production translation catalogue entry for [{$key}].");
    }

    return $translation['text'];
}
