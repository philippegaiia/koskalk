<?php

use App\Actions\Production\CancelProduction;
use App\Actions\Production\PrepareProductionStock;
use App\Actions\Production\ReleaseProductionStock;
use App\Actions\Production\UpdateProductionPlan;
use App\Enums\ProductionRequirementKind;
use App\Enums\ProductionRunStatus;
use App\Enums\StockLotStatus;
use App\Enums\StockMovementType;
use App\Enums\StockReservationStatus;
use App\Models\Ingredient;
use App\Models\PackagingItem;
use App\Models\ProductionRequirement;
use App\Models\ProductionRun;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\StockReservation;
use App\Models\User;
use App\Models\Workspace;
use App\Services\StockPositionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('reserves split ingredient and packaging lots atomically and changes only available stock', function (): void {
    $fixture = productionStockPreparationFixture();
    $production = productionStockProduction($fixture, ProductionRunStatus::Scheduled);
    $ingredientRequirement = productionStockIngredientRequirement($production, $fixture['ingredient'], '130.000000000');
    $packagingRequirement = productionStockPackagingRequirement($production, $fixture['packaging'], 12);
    $ingredientFirst = productionStockLot($fixture, $fixture['ingredient'], '60.000000000', '2026-08-25');
    $ingredientSecond = productionStockLot($fixture, $fixture['ingredient'], '100.000000000', '2026-09-01');
    $packagingLot = productionStockPackagingLot($fixture, $fixture['packaging'], 20);

    $prepared = app(PrepareProductionStock::class)->handle(
        actor: $fixture['owner'],
        productionIds: [$production->id],
        idempotencyKey: 'prepare-split-1',
    );

    expect($prepared)->toHaveCount(1)
        ->and($prepared[0]->status)->toBe(ProductionRunStatus::Reserved)
        ->and(StockReservation::query()->count())->toBe(3)
        ->and(StockReservation::query()->where('production_requirement_id', $ingredientRequirement->id)->sum('quantity'))->toEqual(130)
        ->and(StockReservation::query()->where('production_requirement_id', $packagingRequirement->id)->sum('quantity'))->toEqual(12)
        ->and(StockReservation::query()->where('stock_lot_id', $ingredientFirst->id)->value('quantity'))->toBe('60.000000000')
        ->and(StockReservation::query()->where('stock_lot_id', $ingredientSecond->id)->value('quantity'))->toBe('70.000000000')
        ->and(StockReservation::query()->where('stock_lot_id', $packagingLot->id)->value('quantity'))->toBe('12.000000000');

    $positions = app(StockPositionService::class);

    expect($positions->forWorkspaceSubject($fixture['workspace'], $fixture['ingredient']))->toMatchArray([
        'physical' => '160.000000000',
        'reserved' => '130.000000000',
        'available' => '30.000000000',
        'forecast' => '30.000000000',
    ])->and($positions->forWorkspaceSubject($fixture['workspace'], $fixture['packaging']))->toMatchArray([
        'physical' => '20.000000000',
        'reserved' => '12.000000000',
        'available' => '8.000000000',
        'forecast' => '8.000000000',
    ]);
});

it('posts available reservations when one selected production has a shortage', function (): void {
    $fixture = productionStockPreparationFixture();
    $covered = productionStockProduction($fixture, ProductionRunStatus::Scheduled);
    $short = productionStockProduction($fixture, ProductionRunStatus::Scheduled);
    productionStockIngredientRequirement($covered, $fixture['ingredient'], '10.000000000');
    productionStockIngredientRequirement($short, $fixture['ingredient'], '100.000000000');
    productionStockLot($fixture, $fixture['ingredient'], '20.000000000', '2026-08-25');

    $prepared = app(PrepareProductionStock::class)->handle(
        actor: $fixture['owner'],
        productionIds: [$covered->id, $short->id],
        idempotencyKey: 'prepare-short-bulk',
    );

    // The covered production is fully reserved; the short one reserves what is
    // available (20 g) and stays scheduled for a later pass.
    expect($prepared[0]->status)->toBe(ProductionRunStatus::Reserved)
        ->and($prepared[1]->status)->toBe(ProductionRunStatus::Scheduled)
        ->and(StockReservation::query()->where('production_run_id', $short->id)->sum('quantity'))->toEqual(20);
});

it('requires manual allocations to exactly cover a requirement and validates whole packaging units', function (): void {
    $fixture = productionStockPreparationFixture();
    $production = productionStockProduction($fixture, ProductionRunStatus::Scheduled);
    $ingredientRequirement = productionStockIngredientRequirement($production, $fixture['ingredient'], '10.000000000');
    $first = productionStockLot($fixture, $fixture['ingredient'], '8.000000000', '2026-08-25');
    $second = productionStockLot($fixture, $fixture['ingredient'], '8.000000000', '2026-09-01');

    $prepared = app(PrepareProductionStock::class)->handle(
        actor: $fixture['owner'],
        productionIds: [$production->id],
        idempotencyKey: 'prepare-manual-1',
        manualAllocations: [
            (string) $ingredientRequirement->id => [
                ['stock_lot_id' => $first->id, 'quantity' => '2.000000000'],
                ['stock_lot_id' => $second->id, 'quantity' => '8.000000000'],
            ],
        ],
    );

    expect($prepared[0]->status)->toBe(ProductionRunStatus::Reserved)
        ->and(StockReservation::query()->where('production_requirement_id', $ingredientRequirement->id)->count())->toBe(2);

    $packagingProduction = productionStockProduction($fixture, ProductionRunStatus::Scheduled);
    $packagingRequirement = productionStockPackagingRequirement($packagingProduction, $fixture['packaging'], 3);
    $packagingLot = productionStockPackagingLot($fixture, $fixture['packaging'], 10);

    expect(fn (): array => app(PrepareProductionStock::class)->handle(
        actor: $fixture['owner'],
        productionIds: [$packagingProduction->id],
        idempotencyKey: 'prepare-manual-fractional',
        manualAllocations: [
            (string) $packagingRequirement->id => [
                ['stock_lot_id' => $packagingLot->id, 'quantity' => '2.500000000'],
            ],
        ],
    ))->toThrow(ValidationException::class);
});

it('is idempotent for a repeated preparation key', function (): void {
    $fixture = productionStockPreparationFixture();
    $production = productionStockProduction($fixture, ProductionRunStatus::Scheduled);
    productionStockIngredientRequirement($production, $fixture['ingredient'], '10.000000000');
    productionStockLot($fixture, $fixture['ingredient'], '20.000000000', '2026-08-25');

    $action = app(PrepareProductionStock::class);
    $first = $action->handle($fixture['owner'], [$production->id], 'prepare-idempotent');
    $second = $action->handle($fixture['owner'], [$production->id], 'prepare-idempotent');

    expect($first[0]->status)->toBe(ProductionRunStatus::Reserved)
        ->and($second[0]->status)->toBe(ProductionRunStatus::Reserved)
        ->and(StockReservation::query()->count())->toBe(1);
});

it('releases reservations without deleting history and returns the production to planned', function (): void {
    $fixture = productionStockPreparationFixture();
    $production = productionStockProduction($fixture, ProductionRunStatus::Scheduled);
    productionStockIngredientRequirement($production, $fixture['ingredient'], '10.000000000');
    productionStockLot($fixture, $fixture['ingredient'], '20.000000000', '2026-08-25');
    app(PrepareProductionStock::class)->handle($fixture['owner'], [$production->id], 'prepare-release');

    $released = app(ReleaseProductionStock::class)->handle($fixture['owner'], $production);

    expect($released->status)->toBe(ProductionRunStatus::Scheduled)
        ->and(StockReservation::query()->count())->toBe(1)
        ->and(StockReservation::query()->sole()->status)->toBe(StockReservationStatus::Released)
        ->and(StockReservation::query()->sole()->released_at)->not->toBeNull();
});

it('cancels a reserved production and marks active reservations cancelled', function (): void {
    $fixture = productionStockPreparationFixture();
    $production = productionStockProduction($fixture, ProductionRunStatus::Scheduled);
    productionStockIngredientRequirement($production, $fixture['ingredient'], '10.000000000');
    productionStockLot($fixture, $fixture['ingredient'], '20.000000000', '2026-08-25');
    app(PrepareProductionStock::class)->handle($fixture['owner'], [$production->id], 'prepare-cancel');

    $cancelled = app(CancelProduction::class)->handle($fixture['owner'], $production, 'Supplier cancellation');

    expect($cancelled->status)->toBe(ProductionRunStatus::Cancelled)
        ->and(StockReservation::query()->sole()->status)->toBe(StockReservationStatus::Cancelled)
        ->and(StockReservation::query()->sole()->cancelled_at)->not->toBeNull();
});

it('rejects read-only preparation and cross-workspace production selections', function (): void {
    $fixture = productionStockPreparationFixture();
    $production = productionStockProduction($fixture, ProductionRunStatus::Scheduled);
    productionStockIngredientRequirement($production, $fixture['ingredient'], '10.000000000');
    productionStockLot($fixture, $fixture['ingredient'], '20.000000000', '2026-08-25');
    $fixture['workspace']->productionEntitlement()->update([
        'status' => 'cancelled',
        'cancelled_at' => now(),
    ]);

    expect(fn (): array => app(PrepareProductionStock::class)->handle(
        actor: $fixture['owner'],
        productionIds: [$production->id],
        idempotencyKey: 'prepare-read-only',
    ))->toThrow(ValidationException::class);

    $other = productionStockPreparationFixture();
    $otherProduction = productionStockProduction($other, ProductionRunStatus::Scheduled);

    expect(fn (): array => app(PrepareProductionStock::class)->handle(
        actor: $fixture['owner'],
        productionIds: [$production->id, $otherProduction->id],
        idempotencyKey: 'prepare-cross-workspace',
    ))->toThrow(ValidationException::class);
});

it('keeps released reservation history when a planned production is corrected', function (): void {
    $fixture = productionStockPreparationFixture();
    $production = productionStockProduction($fixture, ProductionRunStatus::Scheduled);
    $ingredientRequirement = productionStockIngredientRequirement($production, $fixture['ingredient'], '130.000000000');
    productionStockLot($fixture, $fixture['ingredient'], '500.000000000', '2026-08-25');

    $prepared = app(PrepareProductionStock::class)->handle(
        actor: $fixture['owner'],
        productionIds: [$production->id],
        idempotencyKey: 'prepare-release-1',
    );
    $released = app(ReleaseProductionStock::class)->handle(
        actor: $fixture['owner'],
        production: $prepared[0],
    );
    $reservation = $released->requirements()->first()->reservations()->first();

    $updated = app(UpdateProductionPlan::class)->handle(
        actor: $fixture['owner'],
        production: $released,
        basisInputValue: '2',
        basisInputUnit: 'kg',
        expectedUnits: 20,
    );

    expect($updated->requirements()->first()->id)->toBe($ingredientRequirement->id)
        ->and($updated->requirements()->first()->required_mass_grams)->toBe('200.000000000')
        ->and($reservation->fresh()->status)->toBe(StockReservationStatus::Released)
        ->and($reservation->fresh()->production_requirement_id)->toBe($ingredientRequirement->id)
        ->and($updated->status)->toBe(ProductionRunStatus::Scheduled);
});

/**
 * @return array{owner: User, workspace: Workspace, ingredient: Ingredient, packaging: PackagingItem}
 */
function productionStockPreparationFixture(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $workspace->productionEntitlement()->create([
        'status' => 'active',
        'activated_at' => now(),
    ]);
    $ingredient = Ingredient::factory()->create(['display_name' => 'Olive oil']);
    $packaging = PackagingItem::factory()->for($workspace)->create(['name' => 'Soap box']);

    return compact('owner', 'workspace', 'ingredient', 'packaging');
}

/**
 * @param  array{owner: User, workspace: Workspace, ingredient: Ingredient, packaging: PackagingItem}  $fixture
 */
function productionStockProduction(array $fixture, ProductionRunStatus $status): ProductionRun
{
    return ProductionRun::factory()->for($fixture['workspace'])->create([
        'status' => $status,
        'planned_for' => '2026-08-20',
        'created_by_user_id' => $fixture['owner']->id,
    ]);
}

function productionStockIngredientRequirement(ProductionRun $production, Ingredient $ingredient, string $quantity): ProductionRequirement
{
    return ProductionRequirement::factory()->for($production, 'productionRun')->create([
        'ingredient_id' => $ingredient->id,
        'packaging_item_id' => null,
        'kind' => ProductionRequirementKind::Ingredient,
        'required_mass_grams' => $quantity,
        'required_units' => null,
        'subject_name_snapshot' => $ingredient->display_name,
    ]);
}

function productionStockPackagingRequirement(ProductionRun $production, PackagingItem $packaging, int $quantity): ProductionRequirement
{
    return ProductionRequirement::factory()->for($production, 'productionRun')->forPackaging($packaging)->create([
        'required_units' => $quantity,
        'subject_name_snapshot' => $packaging->name,
    ]);
}

/**
 * @param  array{owner: User, workspace: Workspace, ingredient: Ingredient, packaging: PackagingItem}  $fixture
 */
function productionStockLot(array $fixture, Ingredient $ingredient, string $quantity, string $expiresAt): StockLot
{
    $lot = StockLot::factory()->for($fixture['workspace'])->released()->create([
        'ingredient_id' => $ingredient->id,
        'packaging_item_id' => null,
        'expires_at' => $expiresAt,
        'released_at' => now(),
    ]);
    StockMovement::factory()->for($lot, 'stockLot')->create([
        'workspace_id' => $fixture['workspace']->id,
        'type' => StockMovementType::OpeningBalance,
        'quantity_delta' => $quantity,
    ]);

    return $lot;
}

/**
 * @param  array{owner: User, workspace: Workspace, ingredient: Ingredient, packaging: PackagingItem}  $fixture
 */
function productionStockPackagingLot(array $fixture, PackagingItem $packaging, int $quantity): StockLot
{
    $lot = StockLot::factory()->for($fixture['workspace'])->forPackaging()->create([
        'ingredient_id' => null,
        'packaging_item_id' => $packaging->id,
        'status' => StockLotStatus::Released,
        'released_at' => now(),
    ]);
    StockMovement::factory()->for($lot, 'stockLot')->create([
        'workspace_id' => $fixture['workspace']->id,
        'type' => StockMovementType::OpeningBalance,
        'quantity_delta' => (string) $quantity,
    ]);

    return $lot;
}
