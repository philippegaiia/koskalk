<?php

use App\Actions\Purchasing\CreatePurchaseOrder;
use App\Actions\Purchasing\PlacePurchaseOrder;
use App\Actions\Purchasing\ReceivePurchaseOrder;
use App\Models\Ingredient;
use App\Models\PackagingItem;
use App\Models\StockLot;
use App\Models\Supplier;
use App\Models\SupplierListing;
use App\Models\User;
use App\Models\Workspace;
use App\OrganicStatus;
use App\PurchaseOrderStatus;
use App\Services\ProductionBenchAccess;
use App\Services\StockPositionService;
use App\StockLotStatus;
use App\StockUnitKind;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates one order with ingredient and packaging listings from the same supplier', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($owner, $workspace);
    $supplier = Supplier::factory()->for($workspace)->create(['default_currency' => 'USD']);
    $ingredientListing = SupplierListing::factory()
        ->for($workspace)
        ->for($supplier)
        ->for(Ingredient::factory())
        ->create(['currency' => 'USD', 'organic_status' => OrganicStatus::Organic]);
    $packaging = PackagingItem::factory()->for($workspace)->create();
    $packagingListing = SupplierListing::factory()
        ->for($workspace)
        ->for($supplier)
        ->state([
            'ingredient_id' => null,
            'packaging_item_id' => $packaging->id,
            'unit_kind' => StockUnitKind::Count,
            'net_quantity' => '100',
            'net_unit' => 'count',
            'canonical_quantity_per_purchase_format' => '100',
            'currency' => 'USD',
        ])
        ->create();

    $order = app(CreatePurchaseOrder::class)->handle(
        actor: $owner,
        workspace: $workspace,
        supplier: $supplier,
        lines: [
            ['listing' => $ingredientListing, 'packs' => 1],
            ['listing' => $packagingListing, 'packs' => 2],
        ],
    );

    expect($order->currency)->toBe('USD')
        ->and($order->lines)->toHaveCount(2)
        ->and($order->lines->whereNotNull('ingredient_id'))->toHaveCount(1)
        ->and($order->lines->whereNotNull('packaging_item_id'))->toHaveCount(1)
        ->and($order->lines->firstWhere('ingredient_id', $ingredientListing->ingredient_id)?->organic_status)
        ->toBe(OrganicStatus::Organic);
});

it('snapshots pack listings and posts partial receipts as distinct lots', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($owner, $workspace);
    $ingredient = Ingredient::factory()->create(['display_name' => 'Olive oil']);
    $supplier = Supplier::factory()->for($workspace)->create(['name' => 'Oil Merchant']);
    $fiveKg = SupplierListing::factory()
        ->for($workspace)
        ->for($supplier)
        ->for($ingredient)
        ->create([
            'supplier_sku' => 'OO-5',
            'purchase_format' => '5 kg pail',
            'unit_kind' => StockUnitKind::Mass,
            'canonical_quantity_per_purchase_format' => '5000',
            'total_price' => '50',
            'currency' => 'EUR',
        ]);
    SupplierListing::factory()
        ->for($workspace)
        ->for($supplier)
        ->for($ingredient)
        ->create([
            'supplier_sku' => 'OO-20',
            'purchase_format' => '20 kg drum',
            'unit_kind' => StockUnitKind::Mass,
            'canonical_quantity_per_purchase_format' => '20000',
            'price_amount' => '175',
            'total_price' => '175',
        ]);

    $order = app(CreatePurchaseOrder::class)->handle(
        actor: $owner,
        workspace: $workspace,
        supplier: $supplier,
        lines: [['listing' => $fiveKg, 'packs' => 3]],
        notes: 'Summer production',
    );
    $line = $order->lines()->sole();

    expect($line->listing_name)->toBe('5 kg pail')
        ->and($line->ordered_packs)->toBe(3)
        ->and($line->canonical_quantity_per_pack)->toBe('5000.000000000')
        ->and($line->pack_price)->toBe('50.000000000')
        ->and($line->expected_quantity)->toBe('15000.000000000')
        ->and($line->expected_cost)->toBe('150.000000000');

    $fiveKg->update(['total_price' => '65', 'canonical_quantity_per_purchase_format' => '5100']);
    expect($line->refresh()->pack_price)->toBe('50.000000000')
        ->and($line->canonical_quantity_per_pack)->toBe('5000.000000000');

    app(PlacePurchaseOrder::class)->handle($owner, $order);

    $firstReceipt = app(ReceivePurchaseOrder::class)->handle(
        actor: $owner,
        order: $order,
        idempotencyKey: 'delivery-one',
        deliveryReference: 'DEL-001',
        lines: [[
            'order_line' => $line,
            'packs_received' => 1,
            'actual_quantity' => '4.9',
            'actual_unit' => 'kg',
            'supplier_batch_number' => 'MILL-2026-7',
            'status' => StockLotStatus::Quarantined,
        ]],
    );
    $firstLot = $firstReceipt->lines()->sole()->stockLot;

    expect($order->refresh()->status)->toBe(PurchaseOrderStatus::PartiallyReceived)
        ->and($firstLot->supplier_batch_number)->toBe('MILL-2026-7')
        ->and($firstLot->historical_unit_cost)->toBe('0.010204081')
        ->and($firstLot->movements()->sole()->quantity_delta)->toBe('4900.000000000');

    $positionsAfterFirstReceipt = app(StockPositionService::class)
        ->forWorkspaceSubject($workspace, $ingredient);
    expect($positionsAfterFirstReceipt['physical'])->toBe('4900.000000000')
        ->and($positionsAfterFirstReceipt['quarantined'])->toBe('0.000000000')
        ->and($positionsAfterFirstReceipt['available'])->toBe('4900.000000000')
        ->and($positionsAfterFirstReceipt['incoming'])->toBe('10000.000000000');

    $secondReceipt = app(ReceivePurchaseOrder::class)->handle(
        actor: $owner,
        order: $order,
        idempotencyKey: 'delivery-two',
        deliveryReference: 'DEL-002',
        lines: [[
            'order_line' => $line,
            'packs_received' => 2,
            'actual_quantity' => '9.7',
            'actual_unit' => 'kg',
            'supplier_batch_number' => 'MILL-2026-7',
            'status' => StockLotStatus::Released,
        ]],
    );
    $secondLot = $secondReceipt->lines()->sole()->stockLot;

    expect($secondLot->id)->not->toBe($firstLot->id)
        ->and($secondLot->internal_lot_code)->not->toBe($firstLot->internal_lot_code)
        ->and($secondLot->supplier_batch_number)->toBe($firstLot->supplier_batch_number)
        ->and($order->refresh()->status)->toBe(PurchaseOrderStatus::Received)
        ->and(StockLot::query()->count())->toBe(2);

    $retriedReceipt = app(ReceivePurchaseOrder::class)->handle(
        actor: $owner,
        order: $order,
        idempotencyKey: 'delivery-two',
        deliveryReference: 'ignored',
        lines: [],
    );
    expect($retriedReceipt->is($secondReceipt))->toBeTrue()
        ->and(StockLot::query()->count())->toBe(2);

    $finalPositions = app(StockPositionService::class)->forWorkspaceSubject($workspace, $ingredient);
    expect($finalPositions['physical'])->toBe('14600.000000000')
        ->and($finalPositions['available'])->toBe('14600.000000000')
        ->and($finalPositions['incoming'])->toBe('0.000000000')
        ->and($finalPositions['forecast'])->toBe('14600.000000000');
});
