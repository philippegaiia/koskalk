<?php

use App\Actions\Inventory\CreateOpeningStockLot;
use App\Actions\Purchasing\CancelPurchaseOrder;
use App\Actions\Purchasing\CreatePurchaseOrder;
use App\Actions\Purchasing\PlacePurchaseOrder;
use App\Actions\Purchasing\ReceiveDirectGoodsReceipt;
use App\Actions\Purchasing\ReceivePurchaseOrder;
use App\Actions\Purchasing\ReverseGoodsReceipt;
use App\GoodsReceiptStatus;
use App\ListingPriceBasis;
use App\MaterialPriceSource;
use App\Models\CurrentMaterialPrice;
use App\Models\GoodsReceipt;
use App\Models\Ingredient;
use App\Models\PackagingItem;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierListing;
use App\Models\User;
use App\Models\Workspace;
use App\PurchaseOrderStatus;
use App\Services\CurrentMaterialPriceService;
use App\Services\ProductionBenchAccess;
use App\Services\StockPositionService;
use App\StockLotStatus;
use App\StockMovementType;
use App\StockUnitKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function purchasingOrder(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($owner, $workspace);
    $supplier = Supplier::factory()->for($workspace)->create();
    $listing = SupplierListing::factory()
        ->for($workspace)
        ->for($supplier)
        ->for(Ingredient::factory()->create())
        ->create();
    $order = app(CreatePurchaseOrder::class)->handle(
        $owner,
        $workspace,
        $supplier,
        [['listing' => $listing, 'packs' => 1]],
    );

    return [$owner, $order];
}

it('cancels an unreceived draft and prevents placement', function (): void {
    [$owner, $order] = purchasingOrder();

    app(CancelPurchaseOrder::class)->handle($owner, $order);

    expect($order->refresh()->status)->toBe(PurchaseOrderStatus::Cancelled)
        ->and(fn () => app(PlacePurchaseOrder::class)->handle($owner, $order))
        ->toThrow(ValidationException::class);
});

it('reverses a receipt with a compensating movement and restores incoming stock', function (): void {
    [$owner, $order] = purchasingOrder();
    app(PlacePurchaseOrder::class)->handle($owner, $order);
    $line = $order->lines()->sole();
    $receipt = app(ReceivePurchaseOrder::class)->handle(
        actor: $owner,
        order: $order,
        idempotencyKey: 'receipt-to-reverse',
        deliveryReference: null,
        lines: [[
            'order_line' => $line,
            'packs_received' => 1,
            'actual_quantity' => '5',
            'actual_unit' => 'kg',
            'status' => StockLotStatus::Released,
        ]],
    );
    $receiptLine = $receipt->lines()->sole();
    $lot = $receiptLine->stockLot;
    $original = $lot->movements()->sole();
    $historicalUnitCost = $lot->historical_unit_cost;
    $receiptPriceSnapshot = $receiptLine->only([
        'receipt_price_basis',
        'receipt_price_amount',
        'receipt_price_unit',
        'purchase_format_price',
        'currency',
        'historical_total_cost',
    ]);
    $lotCostSnapshot = $lot->only(['historical_unit_cost', 'currency']);
    $currentPrice = CurrentMaterialPrice::query()->where('ingredient_id', $line->ingredient_id)->sole();

    app(ReverseGoodsReceipt::class)->handle($owner, $receipt, 'Delivery entered twice');

    $reversal = $lot->movements()->where('type', StockMovementType::ReceiptReversal)->sole();
    $positions = app(StockPositionService::class)->forWorkspaceSubject($order->workspace, $line->ingredient);
    expect($receipt->refresh()->status)->toBe(GoodsReceiptStatus::Reversed)
        ->and($order->refresh()->status)->toBe(PurchaseOrderStatus::Ordered)
        ->and($reversal->quantity_delta)->toBe('-5000.000000000')
        ->and($reversal->reversal_of_stock_movement_id)->toBe($original->id)
        ->and($lot->movements()->sum('quantity_delta'))->toEqual(0)
        ->and($receipt->reversal_reason)->toBe('Delivery entered twice')
        ->and($receiptLine->refresh()->only(array_keys($receiptPriceSnapshot)))->toBe($receiptPriceSnapshot)
        ->and($lot->refresh()->only(array_keys($lotCostSnapshot)))->toBe($lotCostSnapshot)
        ->and($lot->historical_unit_cost)->toBe($historicalUnitCost)
        ->and($currentPrice->refresh()->source_type)->toBe(MaterialPriceSource::ProcurementDocument)
        ->and($currentPrice->source_id)->toBe($order->id)
        ->and($currentPrice->price_per_canonical_unit)->toBe('0.010000000000')
        ->and(CurrentMaterialPrice::query()->count())->toBe(1)
        ->and($positions['physical'])->toBe('0.000000000')
        ->and($positions['available'])->toBe('0.000000000')
        ->and($positions['incoming'])->toBe('5000.000000000');
});

it('requires a nonblank reversal reason before creating compensation', function (?string $reason): void {
    [$owner, $order] = purchasingOrder();
    app(PlacePurchaseOrder::class)->handle($owner, $order);
    $receipt = app(ReceivePurchaseOrder::class)->handle(
        actor: $owner,
        order: $order,
        idempotencyKey: 'receipt-missing-reversal-reason',
        deliveryReference: null,
        lines: [[
            'order_line' => $order->lines()->sole(),
            'packs_received' => 1,
            'actual_quantity' => '5',
            'actual_unit' => 'kg',
        ]],
    );

    expect(fn () => app(ReverseGoodsReceipt::class)->handle($owner, $receipt, $reason))
        ->toThrow(ValidationException::class);

    expect($receipt->refresh()->status)->toBe(GoodsReceiptStatus::Posted)
        ->and(StockMovement::query()->where('type', StockMovementType::ReceiptReversal)->count())->toBe(0);
})->with([null, '', '   ', str_repeat('x', 1001)]);

it('reverses a direct receipt without requiring a purchase order', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($owner, $workspace);
    $supplier = Supplier::factory()->for($workspace)->create();
    $listing = SupplierListing::factory()
        ->for($workspace)
        ->for($supplier)
        ->for(Ingredient::factory()->create())
        ->create();
    $receipt = app(ReceiveDirectGoodsReceipt::class)->handle(
        actor: $owner,
        workspace: $workspace,
        supplier: $supplier,
        idempotencyKey: 'direct-reversal',
        lines: [[
            'listing' => $listing,
            'packs_received' => 1,
            'actual_quantity' => '4.8',
            'actual_unit' => 'kg',
            'receipt_price_basis' => ListingPriceBasis::TotalPurchaseFormat,
            'receipt_price_amount' => '50',
            'currency' => 'EUR',
        ]],
        receivedAt: '2026-08-03',
    );
    $line = $receipt->lines()->sole();
    $lot = $line->stockLot;
    $historicalUnitCost = $lot->historical_unit_cost;
    $receiptPriceSnapshot = $line->only([
        'receipt_price_basis',
        'receipt_price_amount',
        'receipt_price_unit',
        'purchase_format_price',
        'currency',
        'historical_total_cost',
    ]);
    $lotCostSnapshot = $lot->only(['historical_unit_cost', 'currency']);
    $currentPrice = CurrentMaterialPrice::query()->where('ingredient_id', $listing->ingredient_id)->sole();

    app(ReverseGoodsReceipt::class)->handle($owner, $receipt, 'Returned to supplier');
    $positions = app(StockPositionService::class)->forWorkspaceSubject($workspace, $listing->ingredient);

    expect($receipt->refresh()->status)->toBe(GoodsReceiptStatus::Reversed)
        ->and($receipt->reversal_reason)->toBe('Returned to supplier')
        ->and($receipt->purchase_order_id)->toBeNull()
        ->and($lot->movements()->count())->toBe(2)
        ->and($lot->movements()->sum('quantity_delta'))->toEqual(0)
        ->and($line->refresh()->only(array_keys($receiptPriceSnapshot)))->toBe($receiptPriceSnapshot)
        ->and($lot->refresh()->only(array_keys($lotCostSnapshot)))->toBe($lotCostSnapshot)
        ->and($lot->historical_unit_cost)->toBe($historicalUnitCost)
        ->and($currentPrice->refresh()->source_type)->toBe(MaterialPriceSource::SupplierListing)
        ->and($currentPrice->source_id)->toBe($listing->id)
        ->and($currentPrice->price_per_canonical_unit)->toBe('0.010000000000')
        ->and(CurrentMaterialPrice::query()->count())->toBe(1)
        ->and($positions['physical'])->toBe('0.000000000')
        ->and($positions['available'])->toBe('0.000000000')
        ->and($positions['incoming'])->toBe('0.000000000');
});

it('restores the newest still-posted receipt price after reversing a later receipt', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($owner, $workspace);
    $supplier = Supplier::factory()->for($workspace)->create();
    $ingredient = Ingredient::factory()->create();
    $listing = SupplierListing::factory()
        ->for($workspace)
        ->for($supplier)
        ->for($ingredient)
        ->create();
    $firstReceipt = app(ReceiveDirectGoodsReceipt::class)->handle(
        actor: $owner,
        workspace: $workspace,
        supplier: $supplier,
        idempotencyKey: 'receipt-price-fallback-first',
        lines: [[
            'listing' => $listing,
            'packs_received' => 1,
            'actual_quantity' => '5',
            'actual_unit' => 'kg',
            'receipt_price_basis' => ListingPriceBasis::TotalPurchaseFormat,
            'receipt_price_amount' => '40',
            'currency' => 'EUR',
        ]],
        receivedAt: '2026-08-01',
    );
    $secondReceipt = app(ReceiveDirectGoodsReceipt::class)->handle(
        actor: $owner,
        workspace: $workspace,
        supplier: $supplier,
        idempotencyKey: 'receipt-price-fallback-second',
        lines: [[
            'listing' => $listing->refresh(),
            'packs_received' => 1,
            'actual_quantity' => '5',
            'actual_unit' => 'kg',
            'receipt_price_basis' => ListingPriceBasis::TotalPurchaseFormat,
            'receipt_price_amount' => '50',
            'currency' => 'EUR',
        ]],
        receivedAt: '2026-08-02',
    );
    $firstLot = $firstReceipt->lines()->sole()->stockLot;
    $listing->refresh()->update(['is_active' => false]);

    app(ReverseGoodsReceipt::class)->handle($owner, $secondReceipt, 'Later receipt was void');

    $currentPrice = CurrentMaterialPrice::query()->where('ingredient_id', $ingredient->id)->sole();
    expect($currentPrice->source_type)->toBe(MaterialPriceSource::Receipt)
        ->and($currentPrice->source_id)->toBe($firstLot->id)
        ->and($currentPrice->price_per_canonical_unit)->toBe('0.008000000000');
});

it('restores the exact manual price snapshot when multiple lines for the same subject are reversed', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($owner, $workspace);
    $supplier = Supplier::factory()->for($workspace)->create();
    $ingredient = Ingredient::factory()->create();
    $listing = SupplierListing::factory()->for($workspace)->for($supplier)->for($ingredient)->create();

    app(CurrentMaterialPriceService::class)->rememberIngredient(
        workspace: $workspace,
        ingredient: $ingredient,
        pricePerMassUnit: '0.007654321987',
        massUnit: 'g',
        currency: 'USD',
        source: MaterialPriceSource::ManualCosting,
        sourceId: null,
        actor: $owner,
        recordedAt: now()->subDay(),
    );

    $receipt = app(ReceiveDirectGoodsReceipt::class)->handle(
        actor: $owner,
        workspace: $workspace,
        supplier: $supplier,
        idempotencyKey: 'same-subject-exact-price-snapshot',
        lines: [
            [
                'listing' => $listing,
                'packs_received' => 1,
                'actual_quantity' => '5',
                'actual_unit' => 'kg',
                'receipt_price_basis' => ListingPriceBasis::TotalPurchaseFormat,
                'receipt_price_amount' => '40',
                'currency' => 'EUR',
            ],
            [
                'listing' => $listing,
                'packs_received' => 1,
                'actual_quantity' => '5',
                'actual_unit' => 'kg',
                'receipt_price_basis' => ListingPriceBasis::TotalPurchaseFormat,
                'receipt_price_amount' => '50',
                'currency' => 'EUR',
            ],
        ],
        receivedAt: '2026-08-03',
    );
    $listing->refresh()->update(['is_active' => false]);

    app(ReverseGoodsReceipt::class)->handle($owner, $receipt, 'Both lines belong to the same void receipt');

    $currentPrice = CurrentMaterialPrice::query()->where('ingredient_id', $ingredient->id)->sole();
    expect($currentPrice->source_type)->toBe(MaterialPriceSource::ManualCosting)
        ->and($currentPrice->source_id)->toBeNull()
        ->and($currentPrice->currency)->toBe('USD')
        ->and($currentPrice->price_per_canonical_unit)->toBe('0.007654321987')
        ->and($receipt->lines()->first()->previous_material_price_snapshot['source_type'])
        ->toBe(MaterialPriceSource::ManualCosting->value);
});

it('restores an exact costing-backed manual price snapshot after receipt reversal', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($owner, $workspace);
    $supplier = Supplier::factory()->for($workspace)->create();
    $ingredient = Ingredient::factory()->create();
    $listing = SupplierListing::factory()->for($workspace)->for($supplier)->for($ingredient)->create();

    app(CurrentMaterialPriceService::class)->rememberIngredient(
        workspace: $workspace,
        ingredient: $ingredient,
        pricePerMassUnit: '0.008765432109',
        massUnit: 'g',
        currency: 'CAD',
        source: MaterialPriceSource::ManualCosting,
        sourceId: 987654,
        actor: $owner,
        recordedAt: now()->subDay(),
    );
    $receipt = app(ReceiveDirectGoodsReceipt::class)->handle(
        actor: $owner,
        workspace: $workspace,
        supplier: $supplier,
        idempotencyKey: 'receipt-after-costing-backed-price',
        lines: [[
            'listing' => $listing,
            'packs_received' => 1,
            'actual_quantity' => '5',
            'actual_unit' => 'kg',
            'receipt_price_basis' => ListingPriceBasis::TotalPurchaseFormat,
            'receipt_price_amount' => '50',
            'currency' => 'EUR',
        ]],
        receivedAt: '2026-08-03',
    );
    $listing->refresh()->update(['is_active' => false]);

    app(ReverseGoodsReceipt::class)->handle($owner, $receipt, 'Receipt was void');

    $currentPrice = CurrentMaterialPrice::query()->where('ingredient_id', $ingredient->id)->sole();
    expect($currentPrice->source_type)->toBe(MaterialPriceSource::ManualCosting)
        ->and($currentPrice->source_id)->toBe(987654)
        ->and($currentPrice->currency)->toBe('CAD')
        ->and($currentPrice->price_per_canonical_unit)->toBe('0.008765432109');
});

it('restores an opening stock price snapshot after reversing a receipt', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($owner, $workspace);
    $supplier = Supplier::factory()->for($workspace)->create();
    $ingredient = Ingredient::factory()->create();
    $listing = SupplierListing::factory()->for($workspace)->for($supplier)->for($ingredient)->create();
    $openingLot = app(CreateOpeningStockLot::class)->handle(
        actor: $owner,
        workspace: $workspace,
        listing: $listing,
        quantity: '5',
        unit: 'kg',
        pricePerCanonicalUnit: '0.006543210987',
        currency: 'GBP',
        idempotencyKey: 'opening-price-before-receipt',
    );
    $receipt = app(ReceiveDirectGoodsReceipt::class)->handle(
        actor: $owner,
        workspace: $workspace,
        supplier: $supplier,
        idempotencyKey: 'receipt-after-opening-price',
        lines: [[
            'listing' => $listing,
            'packs_received' => 1,
            'actual_quantity' => '5',
            'actual_unit' => 'kg',
            'receipt_price_basis' => ListingPriceBasis::TotalPurchaseFormat,
            'receipt_price_amount' => '50',
            'currency' => 'EUR',
        ]],
        receivedAt: '2026-08-03',
    );
    $listing->refresh()->update(['is_active' => false]);

    app(ReverseGoodsReceipt::class)->handle($owner, $receipt, 'Receipt was void');

    $currentPrice = CurrentMaterialPrice::query()->where('ingredient_id', $ingredient->id)->sole();
    expect($currentPrice->source_type)->toBe(MaterialPriceSource::OpeningStock)
        ->and($currentPrice->source_id)->toBe($openingLot->id)
        ->and($currentPrice->currency)->toBe('GBP')
        ->and($currentPrice->price_per_canonical_unit)->toBe('0.006543210987');
});

it('keeps a newer manual projection when an older receipt is reversed', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($owner, $workspace);
    $supplier = Supplier::factory()->for($workspace)->create();
    $ingredient = Ingredient::factory()->create();
    $listing = SupplierListing::factory()->for($workspace)->for($supplier)->for($ingredient)->create();
    $receipt = app(ReceiveDirectGoodsReceipt::class)->handle(
        actor: $owner,
        workspace: $workspace,
        supplier: $supplier,
        idempotencyKey: 'receipt-before-newer-manual-price',
        lines: [[
            'listing' => $listing,
            'packs_received' => 1,
            'actual_quantity' => '5',
            'actual_unit' => 'kg',
            'receipt_price_basis' => ListingPriceBasis::TotalPurchaseFormat,
            'receipt_price_amount' => '50',
            'currency' => 'EUR',
        ]],
        receivedAt: '2026-08-03',
    );
    app(CurrentMaterialPriceService::class)->rememberIngredient(
        workspace: $workspace,
        ingredient: $ingredient,
        pricePerMassUnit: '0.009876543210',
        massUnit: 'g',
        currency: 'CHF',
        source: MaterialPriceSource::ManualCosting,
        sourceId: null,
        actor: $owner,
        recordedAt: now()->addMinute(),
    );

    app(ReverseGoodsReceipt::class)->handle($owner, $receipt, 'Receipt was void');

    $currentPrice = CurrentMaterialPrice::query()->where('ingredient_id', $ingredient->id)->sole();
    expect($currentPrice->source_type)->toBe(MaterialPriceSource::ManualCosting)
        ->and($currentPrice->currency)->toBe('CHF')
        ->and($currentPrice->price_per_canonical_unit)->toBe('0.009876543210');
});

it('removes a void receipt projection when no fallback remains', function (string $subjectType): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($owner, $workspace);
    $supplier = Supplier::factory()->for($workspace)->create();
    $ingredient = Ingredient::factory()->create();
    $packaging = PackagingItem::factory()->for($workspace)->create();
    $listing = SupplierListing::factory()
        ->for($workspace)
        ->for($supplier)
        ->for($ingredient)
        ->create($subjectType === 'ingredient' ? [] : [
            'ingredient_id' => null,
            'packaging_item_id' => $packaging->id,
            'unit_kind' => StockUnitKind::Count,
            'net_quantity' => '100',
            'net_unit' => 'count',
            'canonical_quantity_per_purchase_format' => '100',
        ]);
    $receipt = app(ReceiveDirectGoodsReceipt::class)->handle(
        actor: $owner,
        workspace: $workspace,
        supplier: $supplier,
        idempotencyKey: 'no-price-fallback-'.$subjectType,
        lines: [[
            'listing' => $listing,
            'packs_received' => 1,
            'actual_quantity' => $subjectType === 'ingredient' ? '5' : '100',
            'actual_unit' => $subjectType === 'ingredient' ? 'kg' : 'count',
            'receipt_price_basis' => ListingPriceBasis::TotalPurchaseFormat,
            'receipt_price_amount' => '50',
            'currency' => 'EUR',
        ]],
        receivedAt: '2026-08-03',
    );
    $listing->refresh()->update(['is_active' => false]);

    app(ReverseGoodsReceipt::class)->handle($owner, $receipt, 'No valid price remains');

    $query = CurrentMaterialPrice::query()->where('workspace_id', $workspace->id);
    $subjectType === 'ingredient'
        ? $query->where('ingredient_id', $ingredient->id)
        : $query->where('packaging_item_id', $packaging->id);

    expect($query->doesntExist())->toBeTrue();
})->with(['ingredient', 'packaging']);

it('rejects a second reversal without creating another movement', function (): void {
    [$owner, $order] = purchasingOrder();
    app(PlacePurchaseOrder::class)->handle($owner, $order);
    $receipt = app(ReceivePurchaseOrder::class)->handle(
        actor: $owner,
        order: $order,
        idempotencyKey: 'receipt-double-reversal',
        deliveryReference: null,
        lines: [[
            'order_line' => $order->lines()->sole(),
            'packs_received' => 1,
            'actual_quantity' => '5',
            'actual_unit' => 'kg',
        ]],
    );

    app(ReverseGoodsReceipt::class)->handle($owner, $receipt, 'First reversal');

    expect(fn () => app(ReverseGoodsReceipt::class)->handle($owner, $receipt, 'Second reversal'))
        ->toThrow(ValidationException::class)
        ->and(StockMovement::query()->count())->toBe(2);
});

it('blocks reversal after the production bench becomes read only', function (): void {
    [$owner, $order] = purchasingOrder();
    app(PlacePurchaseOrder::class)->handle($owner, $order);
    $receipt = app(ReceivePurchaseOrder::class)->handle(
        actor: $owner,
        order: $order,
        idempotencyKey: 'receipt-read-only-reversal',
        deliveryReference: null,
        lines: [[
            'order_line' => $order->lines()->sole(),
            'packs_received' => 1,
            'actual_quantity' => '5',
            'actual_unit' => 'kg',
        ]],
    );
    app(ProductionBenchAccess::class)->cancel($owner, $order->workspace);

    expect(fn () => app(ReverseGoodsReceipt::class)->handle($owner, $receipt, 'Cannot keep delivery'))
        ->toThrow(ValidationException::class);

    expect($receipt->refresh()->status)->toBe(GoodsReceiptStatus::Posted)
        ->and(StockMovement::query()->count())->toBe(1);
});

it('rejects a purchase order idempotency key reused by another order', function (): void {
    [$owner, $firstOrder] = purchasingOrder();
    app(PlacePurchaseOrder::class)->handle($owner, $firstOrder);
    $firstReceipt = app(ReceivePurchaseOrder::class)->handle(
        actor: $owner,
        order: $firstOrder,
        idempotencyKey: 'shared-order-key',
        deliveryReference: null,
        lines: [[
            'order_line' => $firstOrder->lines()->sole(),
            'packs_received' => 1,
            'actual_quantity' => '5',
            'actual_unit' => 'kg',
        ]],
    );
    $supplier = Supplier::factory()->for($firstOrder->workspace)->create();
    $listing = SupplierListing::factory()
        ->for($firstOrder->workspace)
        ->for($supplier)
        ->for(Ingredient::factory()->create())
        ->create();
    $secondOrder = app(CreatePurchaseOrder::class)->handle(
        $owner,
        $firstOrder->workspace,
        $supplier,
        [['listing' => $listing, 'packs' => 1]],
    );
    app(PlacePurchaseOrder::class)->handle($owner, $secondOrder);

    expect(fn () => app(ReceivePurchaseOrder::class)->handle(
        actor: $owner,
        order: $secondOrder,
        idempotencyKey: 'shared-order-key',
        deliveryReference: null,
        lines: [[
            'order_line' => $secondOrder->lines()->sole(),
            'packs_received' => 1,
            'actual_quantity' => '5',
            'actual_unit' => 'kg',
        ]],
    ))->toThrow(ValidationException::class);

    expect(GoodsReceipt::query()->sole()->is($firstReceipt))->toBeTrue()
        ->and(StockLot::query()->count())->toBe(1)
        ->and(StockMovement::query()->count())->toBe(1)
        ->and($secondOrder->refresh()->status)->toBe(PurchaseOrderStatus::Ordered);
});

it('rejects over receipts and lines from another order without partial stock', function (string $invalidInput): void {
    [$owner, $order] = purchasingOrder();
    app(PlacePurchaseOrder::class)->handle($owner, $order);
    $line = $order->lines()->sole();
    [$otherOwner, $otherOrder] = purchasingOrder();
    app(PlacePurchaseOrder::class)->handle($otherOwner, $otherOrder);

    expect(fn () => app(ReceivePurchaseOrder::class)->handle(
        actor: $owner,
        order: $order,
        idempotencyKey: 'invalid-order-receipt-'.$invalidInput,
        deliveryReference: null,
        lines: [[
            'order_line' => $invalidInput === 'foreign line' ? $otherOrder->lines()->sole() : $line,
            'packs_received' => $invalidInput === 'over receipt' ? 2 : 1,
            'actual_quantity' => '5',
            'actual_unit' => 'kg',
        ]],
    ))->toThrow(ValidationException::class);

    expect(GoodsReceipt::query()->count())->toBe(0)
        ->and(StockLot::query()->count())->toBe(0)
        ->and(StockMovement::query()->count())->toBe(0)
        ->and($order->refresh()->status)->toBe(PurchaseOrderStatus::Ordered);
})->with(['over receipt', 'foreign line']);

it('blocks purchase order receipt posting after the production bench becomes read only', function (): void {
    [$owner, $order] = purchasingOrder();
    app(PlacePurchaseOrder::class)->handle($owner, $order);
    app(ProductionBenchAccess::class)->cancel($owner, $order->workspace);

    expect(fn () => app(ReceivePurchaseOrder::class)->handle(
        actor: $owner,
        order: $order,
        idempotencyKey: 'order-read-only-receipt',
        deliveryReference: null,
        lines: [[
            'order_line' => $order->lines()->sole(),
            'packs_received' => 1,
            'actual_quantity' => '5',
            'actual_unit' => 'kg',
        ]],
    ))->toThrow(ValidationException::class);

    expect(GoodsReceipt::query()->count())->toBe(0)
        ->and(StockMovement::query()->count())->toBe(0);
});

it('rejects purchase orders that are not receivable when using a fresh submission key', function (string $state): void {
    [$owner, $order] = purchasingOrder();
    $line = $order->lines()->sole();

    if ($state === 'cancelled') {
        app(CancelPurchaseOrder::class)->handle($owner, $order);
    }

    if ($state === 'fully received') {
        app(PlacePurchaseOrder::class)->handle($owner, $order);
        app(ReceivePurchaseOrder::class)->handle(
            actor: $owner,
            order: $order,
            idempotencyKey: 'completed-order-receipt',
            deliveryReference: null,
            lines: [[
                'order_line' => $line,
                'packs_received' => 1,
                'actual_quantity' => '5',
                'actual_unit' => 'kg',
            ]],
        );
    }

    $expectedStatus = $order->refresh()->status;
    $receiptCount = GoodsReceipt::query()->count();
    $lotCount = StockLot::query()->count();
    $movementCount = StockMovement::query()->count();

    expect(fn () => app(ReceivePurchaseOrder::class)->handle(
        actor: $owner,
        order: $order,
        idempotencyKey: 'fresh-invalid-status-'.$state,
        deliveryReference: null,
        lines: [[
            'order_line' => $line,
            'packs_received' => 1,
            'actual_quantity' => '5',
            'actual_unit' => 'kg',
        ]],
    ))->toThrow(ValidationException::class);

    expect($order->refresh()->status)->toBe($expectedStatus)
        ->and(GoodsReceipt::query()->count())->toBe($receiptCount)
        ->and(StockLot::query()->count())->toBe($lotCount)
        ->and(StockMovement::query()->count())->toBe($movementCount);
})->with(['draft', 'cancelled', 'fully received']);

it('falls back to a total purchase format snapshot for legacy ordered lines', function (): void {
    [$owner, $order] = purchasingOrder();
    app(PlacePurchaseOrder::class)->handle($owner, $order);
    $line = $order->lines()->sole();
    DB::table($line->getTable())->where('id', $line->id)->update([
        'price_basis' => null,
        'price_amount' => null,
    ]);

    $receipt = app(ReceivePurchaseOrder::class)->handle(
        actor: $owner,
        order: $order,
        idempotencyKey: 'legacy-price-snapshot',
        deliveryReference: null,
        lines: [[
            'order_line' => $line->refresh(),
            'packs_received' => 1,
            'actual_quantity' => '5',
            'actual_unit' => 'kg',
        ]],
    );

    $receiptLine = $receipt->lines()->sole();

    expect($receiptLine->receipt_price_basis)->toBe(ListingPriceBasis::TotalPurchaseFormat)
        ->and($receiptLine->receipt_price_amount)->toBe($line->pack_price)
        ->and($receiptLine->purchase_format_price)->toBe($line->pack_price);
});

it('rejects an ordered line without a supplier listing before posting any stock', function (): void {
    [$owner, $order] = purchasingOrder();
    app(PlacePurchaseOrder::class)->handle($owner, $order);
    $line = $order->lines()->sole();
    DB::table($line->getTable())->where('id', $line->id)->update(['supplier_listing_id' => null]);

    expect(fn () => app(ReceivePurchaseOrder::class)->handle(
        actor: $owner,
        order: $order,
        idempotencyKey: 'missing-listing',
        deliveryReference: null,
        lines: [[
            'order_line' => $line->refresh(),
            'packs_received' => 1,
            'actual_quantity' => '5',
            'actual_unit' => 'kg',
        ]],
    ))->toThrow(ValidationException::class, 'A supplier listing is required before this line can be received.');

    expect(GoodsReceipt::query()->count())->toBe(0)
        ->and(StockLot::query()->count())->toBe(0)
        ->and(StockMovement::query()->count())->toBe(0)
        ->and($order->refresh()->status)->toBe(PurchaseOrderStatus::Ordered);
});

it('validates purchase order receipt keys and hashes maximum keys into bounded movement keys', function (string $key, bool $valid): void {
    [$owner, $order] = purchasingOrder();
    app(PlacePurchaseOrder::class)->handle($owner, $order);
    $post = fn () => app(ReceivePurchaseOrder::class)->handle(
        actor: $owner,
        order: $order,
        idempotencyKey: $key,
        deliveryReference: null,
        lines: [[
            'order_line' => $order->lines()->sole(),
            'packs_received' => 1,
            'actual_quantity' => '5',
            'actual_unit' => 'kg',
        ]],
    );

    if (! $valid) {
        expect($post)->toThrow(ValidationException::class)
            ->and(GoodsReceipt::query()->count())->toBe(0);

        return;
    }

    $receipt = $post();
    $retried = $post();
    $movementKey = $receipt->lines()->sole()->stockLot->movements()->sole()->idempotency_key;

    expect($retried->is($receipt))->toBeTrue()
        ->and(strlen($movementKey))->toBeLessThanOrEqual(120)
        ->and($movementKey)->toStartWith('receipt-line:')
        ->and(StockMovement::query()->count())->toBe(1);
})->with([
    'blank' => ['', false],
    'whitespace' => ['   ', false],
    'too long' => [str_repeat('k', 121), false],
    'maximum boundary' => [str_repeat('k', 120), true],
]);

it('validates purchase order receipt dates and metadata before any write', function (string $case): void {
    [$owner, $order] = purchasingOrder();
    app(PlacePurchaseOrder::class)->handle($owner, $order);
    $line = [
        'order_line' => $order->lines()->sole(),
        'packs_received' => 1,
        'actual_quantity' => '5',
        'actual_unit' => 'kg',
    ];
    $receivedAt = '2026-08-03';
    $deliveryReference = null;
    $notes = null;

    match ($case) {
        'receipt date' => $receivedAt = '2026-02-31',
        'expiry' => $line['expires_at'] = '2026-02-31',
        'supplier batch' => $line['supplier_batch_number'] = str_repeat('b', 121),
        'line notes' => $line['notes'] = str_repeat('n', 5001),
        'delivery reference' => $deliveryReference = str_repeat('r', 256),
        'receipt notes' => $notes = str_repeat('n', 5001),
    };

    expect(fn () => app(ReceivePurchaseOrder::class)->handle(
        actor: $owner,
        order: $order,
        idempotencyKey: 'invalid-order-metadata-'.$case,
        deliveryReference: $deliveryReference,
        lines: [$line],
        receivedAt: $receivedAt,
        notes: $notes,
    ))->toThrow(ValidationException::class);

    expect(GoodsReceipt::query()->count())->toBe(0)
        ->and(StockMovement::query()->count())->toBe(0);
})->with(['receipt date', 'expiry', 'supplier batch', 'line notes', 'delivery reference', 'receipt notes']);
