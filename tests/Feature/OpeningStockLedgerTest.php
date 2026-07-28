<?php

use App\Actions\Inventory\CreateOpeningStockLot;
use App\Actions\Inventory\QuarantineStockLot;
use App\Actions\Inventory\ReleaseStockLot;
use App\Models\Ingredient;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\UserPackagingItem;
use App\Models\Workspace;
use App\Services\ProductionBenchAccess;
use App\StockLotStatus;
use App\StockUnitKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function activeProductionWorkspace(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($owner, $workspace);

    return [$owner, $workspace];
}

it('posts mass opening stock in canonical grams and is idempotent', function (): void {
    [$owner, $workspace] = activeProductionWorkspace();
    $ingredient = Ingredient::factory()->create();

    $action = app(CreateOpeningStockLot::class);
    $lot = $action->handle(
        actor: $owner,
        workspace: $workspace,
        subject: $ingredient,
        quantity: '2.5',
        unit: 'lb',
        status: StockLotStatus::Released,
        idempotencyKey: 'opening-almond-oil',
        supplierBatchNumber: 'SUP-42',
        provenanceComplete: false,
    );
    $retriedLot = $action->handle(
        actor: $owner,
        workspace: $workspace,
        subject: $ingredient,
        quantity: '2.5',
        unit: 'lb',
        status: StockLotStatus::Released,
        idempotencyKey: 'opening-almond-oil',
    );

    expect($retriedLot->is($lot))->toBeTrue()
        ->and($lot->unit_kind)->toBe(StockUnitKind::Mass)
        ->and($lot->status)->toBe(StockLotStatus::Released)
        ->and($lot->supplier_batch_number)->toBe('SUP-42')
        ->and($lot->provenance_complete)->toBeFalse()
        ->and($lot->internal_lot_code)->toMatch('/^SK-\d{6}-\d{4}$/')
        ->and($lot->movements)->toHaveCount(1)
        ->and($lot->movements->first()->quantity_delta)->toBe('1133.980925000')
        ->and($lot->movements->first()->original_quantity)->toBe('2.500000000')
        ->and($lot->movements->first()->original_unit)->toBe('lb')
        ->and(StockMovement::query()->count())->toBe(1);
});

it('requires positive whole counts for packaging opening stock', function (): void {
    [$owner, $workspace] = activeProductionWorkspace();
    $packaging = UserPackagingItem::factory()->for($owner)->create();

    expect(fn () => app(CreateOpeningStockLot::class)->handle(
        actor: $owner,
        workspace: $workspace,
        subject: $packaging,
        quantity: '12.5',
        unit: 'count',
        status: StockLotStatus::Quarantined,
        idempotencyKey: 'opening-jars',
    ))->toThrow(ValidationException::class);
});

it('changes release state without rewriting stock history', function (): void {
    [$owner, $workspace] = activeProductionWorkspace();
    $ingredient = Ingredient::factory()->create();
    $lot = app(CreateOpeningStockLot::class)->handle(
        actor: $owner,
        workspace: $workspace,
        subject: $ingredient,
        quantity: '5',
        unit: 'kg',
        status: StockLotStatus::Quarantined,
        idempotencyKey: 'opening-oil',
    );
    $movementId = $lot->movements()->sole()->id;

    app(ReleaseStockLot::class)->handle($owner, $lot, 'CoA checked');
    expect($lot->refresh()->status)->toBe(StockLotStatus::Released)
        ->and($lot->release_note)->toBe('CoA checked');

    app(QuarantineStockLot::class)->handle($owner, $lot, 'Retesting');
    expect($lot->refresh()->status)->toBe(StockLotStatus::Quarantined)
        ->and($lot->movements()->sole()->id)->toBe($movementId);
});

it('blocks opening stock mutations after cancellation', function (): void {
    [$owner, $workspace] = activeProductionWorkspace();
    app(ProductionBenchAccess::class)->cancel($owner, $workspace);

    expect(fn () => app(CreateOpeningStockLot::class)->handle(
        actor: $owner,
        workspace: $workspace,
        subject: Ingredient::factory()->create(),
        quantity: '1',
        unit: 'kg',
        status: StockLotStatus::Released,
        idempotencyKey: 'blocked',
    ))->toThrow(ValidationException::class);
});
