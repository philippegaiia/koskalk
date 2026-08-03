<?php

use App\GoodsReceiptSource;
use App\GoodsReceiptStatus;
use App\ListingPriceBasis;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\PackagingItem;
use App\Models\StockLot;
use App\Models\Supplier;
use App\Models\SupplierListing;
use App\Models\Workspace;
use App\OrganicStatus;
use App\StockLotOrigin;
use App\StockLotStatus;
use App\StockUnitKind;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('stores a direct receipt with required supplier linkage and no purchase order', function (): void {
    $workspace = Workspace::factory()->create();
    $supplier = Supplier::factory()->for($workspace)->create();

    $receipt = GoodsReceipt::factory()
        ->for($workspace)
        ->for($supplier)
        ->direct()
        ->create();

    expect($receipt->source)->toBe(GoodsReceiptSource::Direct)
        ->and($receipt->purchase_order_id)->toBeNull()
        ->and($receipt->workspace->is($workspace))->toBeTrue()
        ->and($receipt->supplier->is($supplier))->toBeTrue();

    expect(fn () => GoodsReceipt::query()->create([
        'workspace_id' => $workspace->id,
        'purchase_order_id' => null,
        'source' => GoodsReceiptSource::Direct,
        'received_at' => now()->toDateString(),
        'status' => GoodsReceiptStatus::Posted,
        'idempotency_key' => fake()->uuid(),
    ]))->toThrow(QueryException::class);
});

it('builds coherent purchase order receipt factory relationships', function (): void {
    $receipt = GoodsReceipt::factory()->create();
    $line = GoodsReceiptLine::factory()->create();

    expect($receipt->workspace_id)->toBe($receipt->purchaseOrder->workspace_id)
        ->and($receipt->supplier_id)->toBe($receipt->purchaseOrder->supplier_id)
        ->and($line->goodsReceipt->purchase_order_id)->toBe($line->purchaseOrderLine->purchase_order_id)
        ->and($line->supplier_listing_id)->toBe($line->purchaseOrderLine->supplier_listing_id)
        ->and($line->stockLot->workspace_id)->toBe($line->goodsReceipt->workspace_id)
        ->and($line->stockLot->supplier_listing_id)->toBe($line->supplier_listing_id)
        ->and($line->stockLot->ingredient_id)->toBe($line->purchaseOrderLine->ingredient_id)
        ->and($line->stockLot->packaging_item_id)->toBe($line->purchaseOrderLine->packaging_item_id);
});

it('builds coherent direct receipt line factory relationships', function (): void {
    $line = GoodsReceiptLine::factory()->direct()->create();

    expect($line->purchase_order_line_id)->toBeNull()
        ->and($line->goodsReceipt->source)->toBe(GoodsReceiptSource::Direct)
        ->and($line->goodsReceipt->purchase_order_id)->toBeNull()
        ->and($line->goodsReceipt->workspace_id)->toBe($line->supplierListing->workspace_id)
        ->and($line->goodsReceipt->supplier_id)->toBe($line->supplierListing->supplier_id)
        ->and($line->stockLot->workspace_id)->toBe($line->supplierListing->workspace_id)
        ->and($line->stockLot->supplier_listing_id)->toBe($line->supplier_listing_id)
        ->and($line->stockLot->ingredient_id)->toBe($line->supplierListing->ingredient_id)
        ->and($line->stockLot->packaging_item_id)->toBe($line->supplierListing->packaging_item_id);
});

it('stores immutable receipt price snapshots against the required supplier listing', function (): void {
    [$receipt, $listing, $lot] = directReceiptFixture();

    $line = GoodsReceiptLine::factory()
        ->for($receipt)
        ->for($listing, 'supplierListing')
        ->for($lot, 'stockLot')
        ->direct()
        ->create([
            'packs_received' => 2,
            'receipt_price_basis' => ListingPriceBasis::PerUnit,
            'receipt_price_amount' => '9.75',
            'receipt_price_unit' => 'kg',
            'purchase_format_price' => '48.75',
            'currency' => 'EUR',
            'notes' => 'One container was dented.',
        ]);

    expect($line->purchase_order_line_id)->toBeNull()
        ->and($line->supplierListing->is($listing))->toBeTrue()
        ->and($line->receipt_price_basis)->toBe(ListingPriceBasis::PerUnit)
        ->and($line->receipt_price_amount)->toBe('9.750000000')
        ->and($line->purchase_format_price)->toBe('48.750000000')
        ->and($line->currency)->toBe('EUR');
});

it('requires every receipt line to retain its supplier listing snapshot', function (): void {
    [$receipt, $listing, $lot] = directReceiptFixture();

    expect(fn () => GoodsReceiptLine::factory()
        ->for($receipt)
        ->for($listing, 'supplierListing')
        ->for($lot, 'stockLot')
        ->direct()
        ->create(['supplier_listing_id' => null]))
        ->toThrow(QueryException::class);
});

it('prevents receipt identity and receipt line snapshots from being changed or deleted', function (): void {
    [$receipt, $listing, $lot] = directReceiptFixture();
    $line = GoodsReceiptLine::factory()
        ->for($receipt)
        ->for($listing, 'supplierListing')
        ->for($lot, 'stockLot')
        ->direct()
        ->create();

    expect(fn () => $receipt->update(['received_at' => now()->addDay()->toDateString()]))
        ->toThrow(LogicException::class, 'Posted goods receipt identity is immutable')
        ->and(fn () => $line->update(['historical_total_cost' => '1']))
        ->toThrow(LogicException::class, 'Goods receipt lines are immutable')
        ->and(fn () => $line->delete())
        ->toThrow(LogicException::class, 'Goods receipt lines are immutable');
});

it('protects acquisition identity and cost on receipt lots while allowing release metadata', function (): void {
    [$receipt, $listing, $lot] = directReceiptFixture();
    GoodsReceiptLine::factory()
        ->for($receipt)
        ->for($listing, 'supplierListing')
        ->for($lot, 'stockLot')
        ->direct()
        ->create();

    $otherWorkspace = Workspace::factory()->create();
    $otherListing = SupplierListing::factory()->for($lot->workspace)->create();
    $packagingItem = PackagingItem::factory()->for($lot->workspace)->create();
    $immutableUpdates = [
        'workspace_id' => $otherWorkspace->id,
        'supplier_listing_id' => $otherListing->id,
        'ingredient_id' => null,
        'packaging_item_id' => $packagingItem->id,
        'internal_lot_code' => 'CHANGED-LOT-CODE',
        'supplier_batch_number' => 'CHANGED-BATCH',
        'origin' => StockLotOrigin::OpeningBalance,
        'unit_kind' => StockUnitKind::Count,
        'stocked_at' => now()->addDay()->toDateString(),
        'expires_at' => now()->addYear()->toDateString(),
        'historical_unit_cost' => '999',
        'currency' => 'USD',
        'organic_status' => OrganicStatus::Organic,
    ];

    foreach ($immutableUpdates as $attribute => $value) {
        expect(fn () => $lot->fresh()->update([$attribute => $value]))
            ->toThrow(LogicException::class, 'Receipt lot acquisition fields are immutable');
    }

    $lot->refresh();
    $lot->update([
        'status' => StockLotStatus::Released,
        'released_at' => now(),
        'release_note' => 'Quality review complete.',
    ]);

    expect($lot->refresh()->status)->toBe(StockLotStatus::Released)
        ->and($lot->release_note)->toBe('Quality review complete.');
});

it('refuses to partially roll back receipt schema while direct receipt data exists', function (): void {
    [$receipt, $listing, $lot] = directReceiptFixture();
    GoodsReceiptLine::factory()
        ->for($receipt)
        ->for($listing, 'supplierListing')
        ->for($lot, 'stockLot')
        ->direct()
        ->create();

    $migration = require database_path('migrations/2026_08_02_210744_extend_goods_receipts_for_direct_receipts_and_price_snapshots.php');
    $exception = null;

    try {
        $migration->down();
    } catch (Throwable $caught) {
        $exception = $caught;
    }

    expect($exception)
        ->toBeInstanceOf(LogicException::class)
        ->and($exception?->getMessage())->toContain('remove or migrate direct receipt data')
        ->and(Schema::hasColumn('goods_receipts', 'source'))->toBeTrue()
        ->and(Schema::hasColumn('goods_receipt_lines', 'supplier_listing_id'))->toBeTrue()
        ->and(Schema::hasColumn('goods_receipt_lines', 'purchase_order_line_id'))->toBeTrue();
});

it('fully restores the original receipt schema when no direct receipt data exists', function (): void {
    $migration = require database_path('migrations/2026_08_02_210744_extend_goods_receipts_for_direct_receipts_and_price_snapshots.php');

    $migration->down();

    $receiptColumns = collect(Schema::getColumns('goods_receipts'))->keyBy('name');
    $lineColumns = collect(Schema::getColumns('goods_receipt_lines'))->keyBy('name');

    expect(Schema::hasColumn('goods_receipts', 'source'))->toBeFalse()
        ->and(Schema::hasColumn('goods_receipts', 'supplier_id'))->toBeFalse()
        ->and(Schema::hasColumn('goods_receipts', 'reversal_reason'))->toBeFalse()
        ->and($receiptColumns->get('purchase_order_id')['nullable'])->toBeFalse()
        ->and(Schema::hasColumn('goods_receipt_lines', 'supplier_listing_id'))->toBeFalse()
        ->and(Schema::hasColumn('goods_receipt_lines', 'receipt_price_amount'))->toBeFalse()
        ->and($lineColumns->get('purchase_order_line_id')['nullable'])->toBeFalse();

    $migration->up();
});

/**
 * @return array{GoodsReceipt, SupplierListing, StockLot}
 */
function directReceiptFixture(): array
{
    $workspace = Workspace::factory()->create();
    $supplier = Supplier::factory()->for($workspace)->create();
    $listing = SupplierListing::factory()->for($workspace)->for($supplier)->create();
    $receipt = GoodsReceipt::factory()
        ->for($workspace)
        ->for($supplier)
        ->direct()
        ->create();
    $lot = StockLot::factory()
        ->for($workspace)
        ->for($listing, 'supplierListing')
        ->for($listing->ingredient)
        ->create([
            'origin' => StockLotOrigin::PurchaseReceipt,
            'historical_unit_cost' => '0.010000000',
            'currency' => 'EUR',
        ]);

    return [$receipt, $listing, $lot];
}
