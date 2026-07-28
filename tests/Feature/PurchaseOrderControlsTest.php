<?php

use App\Actions\Purchasing\CancelPurchaseOrder;
use App\Actions\Purchasing\CreatePurchaseOrder;
use App\Actions\Purchasing\PlacePurchaseOrder;
use App\Actions\Purchasing\ReceivePurchaseOrder;
use App\Actions\Purchasing\ReverseGoodsReceipt;
use App\GoodsReceiptStatus;
use App\Models\Ingredient;
use App\Models\Supplier;
use App\Models\SupplierListing;
use App\Models\User;
use App\Models\Workspace;
use App\PurchaseOrderStatus;
use App\Services\ProductionBenchAccess;
use App\StockLotStatus;
use App\StockMovementType;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
