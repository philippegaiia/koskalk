<?php

use App\Actions\Purchasing\CancelPurchaseOrder;
use App\Actions\Purchasing\CreatePurchaseOrder;
use App\Actions\Purchasing\PlacePurchaseOrder;
use App\Actions\Purchasing\ReceivePurchaseOrder;
use App\Actions\Purchasing\ReverseGoodsReceipt;
use App\GoodsReceiptStatus;
use App\ListingPriceBasis;
use App\Models\GoodsReceipt;
use App\Models\Ingredient;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierListing;
use App\Models\User;
use App\Models\Workspace;
use App\PurchaseOrderStatus;
use App\Services\ProductionBenchAccess;
use App\StockLotStatus;
use App\StockMovementType;
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
    $lot = $receipt->lines()->sole()->stockLot;
    $original = $lot->movements()->sole();

    app(ReverseGoodsReceipt::class)->handle($owner, $receipt, 'Delivery entered twice');

    $reversal = $lot->movements()->where('type', StockMovementType::ReceiptReversal)->sole();
    expect($receipt->refresh()->status)->toBe(GoodsReceiptStatus::Reversed)
        ->and($order->refresh()->status)->toBe(PurchaseOrderStatus::Ordered)
        ->and($reversal->quantity_delta)->toBe('-5000.000000000')
        ->and($reversal->reversal_of_stock_movement_id)->toBe($original->id)
        ->and($lot->movements()->sum('quantity_delta'))->toEqual(0);
});

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
