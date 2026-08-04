<?php

use App\Actions\Inventory\AddStockLotCostAdjustment;
use App\Actions\Purchasing\ReceiveDirectGoodsReceipt;
use App\Enums\StockLotCostAdjustmentType;
use App\ListingPriceBasis;
use App\Models\GoodsReceiptLine;
use App\Models\Ingredient;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierListing;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProductionBenchAccess;
use App\StockUnitKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function stockAdjustmentContext(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($owner, $workspace);
    $supplier = Supplier::factory()->for($workspace)->create();
    $ingredient = Ingredient::factory()->create();
    $listing = SupplierListing::factory()
        ->for($workspace)
        ->for($supplier)
        ->for($ingredient)
        ->create([
            'unit_kind' => StockUnitKind::Mass,
            'currency' => 'EUR',
            'net_quantity' => '5',
            'net_unit' => 'kg',
            'canonical_quantity_per_purchase_format' => '5000',
        ]);
    $receipt = app(ReceiveDirectGoodsReceipt::class)->handle(
        actor: $owner,
        workspace: $workspace,
        supplier: $supplier,
        idempotencyKey: 'adjustment-receipt',
        lines: [[
            'listing' => $listing,
            'packs_received' => 1,
            'actual_quantity' => '5',
            'actual_unit' => 'kg',
            'receipt_price_basis' => ListingPriceBasis::TotalPurchaseFormat,
            'receipt_price_amount' => '100',
            'currency' => 'EUR',
        ]],
        receivedAt: '2026-08-03',
    );

    return [$owner, $workspace, $receipt->lines()->sole()->stockLot];
}

it('adds signed auditable costs without changing the stock movement', function (): void {
    [$owner, $workspace, $lot] = stockAdjustmentContext();
    $movementCount = StockMovement::query()->count();

    $shipping = app(AddStockLotCostAdjustment::class)->handle(
        actor: $owner,
        workspace: $workspace,
        lot: $lot,
        type: StockLotCostAdjustmentType::Shipping,
        amount: '10',
        currency: 'EUR',
        reason: 'Allocated delivery charge',
    );
    $discount = app(AddStockLotCostAdjustment::class)->handle(
        actor: $owner,
        workspace: $workspace,
        lot: $lot,
        type: StockLotCostAdjustmentType::Discount,
        amount: '-5',
        currency: 'EUR',
        reason: 'Supplier credit on the invoice',
    );

    expect($shipping->costing_amount)->toBe('10.000000000')
        ->and($discount->costing_amount)->toBe('-5.000000000')
        ->and($shipping->costing_currency)->toBe('EUR')
        ->and($lot->fresh()->effectiveCostingTotalCost())->toBe('105.000000000')
        ->and($lot->fresh()->effectiveCostingUnitCost())->toBe('0.021000000')
        ->and(StockMovement::query()->count())->toBe($movementCount)
        ->and(GoodsReceiptLine::query()->sole()->historical_total_cost)->toBe('100.000000000');
});

it('keeps adjustments immutable and rejects missing reasons or foreign lots', function (): void {
    [$owner, $workspace, $lot] = stockAdjustmentContext();
    $adjustment = app(AddStockLotCostAdjustment::class)->handle(
        actor: $owner,
        workspace: $workspace,
        lot: $lot,
        type: StockLotCostAdjustmentType::ImportDuty,
        amount: '7',
        currency: 'EUR',
        reason: 'Customs charge',
    );

    expect(fn () => $adjustment->update(['reason' => 'changed']))
        ->toThrow(LogicException::class)
        ->and(fn () => $adjustment->delete())
        ->toThrow(LogicException::class)
        ->and(fn () => app(AddStockLotCostAdjustment::class)->handle(
            actor: $owner,
            workspace: $workspace,
            lot: $lot,
            type: StockLotCostAdjustmentType::Shipping,
            amount: '1',
            currency: 'EUR',
            reason: '',
        ))->toThrow(ValidationException::class)
        ->and(fn () => app(AddStockLotCostAdjustment::class)->handle(
            actor: $owner,
            workspace: $workspace,
            lot: StockLot::factory()->create(),
            type: StockLotCostAdjustmentType::Shipping,
            amount: '1',
            currency: 'EUR',
            reason: 'Foreign lot',
        ))->toThrow(ValidationException::class);
});

it('stores the original adjustment amount and a manual conversion snapshot', function (): void {
    Http::fake();
    [$owner, $workspace, $lot] = stockAdjustmentContext();

    $adjustment = app(AddStockLotCostAdjustment::class)->handle(
        actor: $owner,
        workspace: $workspace,
        lot: $lot,
        type: StockLotCostAdjustmentType::ImportDuty,
        amount: '10',
        currency: 'USD',
        reason: 'Import duty paid in supplier currency',
        manualRate: '0.9',
    );

    expect($adjustment->amount)->toBe('10.000000000')
        ->and($adjustment->currency)->toBe('USD')
        ->and($adjustment->costing_amount)->toBe('9.000000000')
        ->and($adjustment->costing_currency)->toBe('EUR')
        ->and($adjustment->exchange_rate)->toBe('0.900000000000')
        ->and($adjustment->exchange_rate_provider)->toBe('manual')
        ->and($adjustment->exchange_rate_is_manual)->toBeTrue();

    Http::assertNothingSent();
});

it('compensates an adjustment with a second immutable record', function (): void {
    [$owner, $workspace, $lot] = stockAdjustmentContext();
    $action = app(AddStockLotCostAdjustment::class);
    $adjustment = $action->handle(
        actor: $owner,
        workspace: $workspace,
        lot: $lot,
        type: StockLotCostAdjustmentType::PriceCorrection,
        amount: '12',
        currency: 'EUR',
        reason: 'Corrected invoice total',
    );

    $compensation = $action->compensate(
        actor: $owner,
        workspace: $workspace,
        adjustment: $adjustment,
        reason: 'Correction entered in error',
    );

    expect($compensation->compensates_adjustment_id)->toBe($adjustment->id)
        ->and($lot->fresh()->effectiveCostingTotalCost())->toBe('100.000000000')
        ->and($lot->costAdjustments()->count())->toBe(2);
});

it('reuses the original exchange-rate snapshot when compensating an adjustment', function (): void {
    Http::fake([
        'https://api.frankfurter.dev/v2/rate/USD/EUR*' => Http::sequence()
            ->push(['date' => '2026-08-03', 'base' => 'USD', 'quote' => 'EUR', 'rate' => 0.9])
            ->push(['date' => '2026-08-04', 'base' => 'USD', 'quote' => 'EUR', 'rate' => 1.1]),
    ]);
    [$owner, $workspace, $lot] = stockAdjustmentContext();
    $action = app(AddStockLotCostAdjustment::class);
    $adjustment = $action->handle(
        actor: $owner,
        workspace: $workspace,
        lot: $lot,
        type: StockLotCostAdjustmentType::ImportDuty,
        amount: '10',
        currency: 'USD',
        reason: 'Import duty recorded at receipt date',
    );
    Cache::forget('exchange-rate:frankfurter:'.now()->toDateString().':USD:EUR');

    $compensation = $action->compensate(
        actor: $owner,
        workspace: $workspace,
        adjustment: $adjustment,
        reason: 'Import duty correction',
    );

    expect($adjustment->exchange_rate)->toBe('0.900000000000')
        ->and($compensation->exchange_rate)->toBe('0.900000000000')
        ->and($compensation->costing_amount)->toBe('-9.000000000')
        ->and($lot->fresh()->effectiveCostingTotalCost())->toBe('100.000000000');

    Http::assertSentCount(1);
});
