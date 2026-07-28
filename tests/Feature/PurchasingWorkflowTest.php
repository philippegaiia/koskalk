<?php

use App\Actions\Purchasing\CreatePurchaseOrder;
use App\Actions\Purchasing\PlacePurchaseOrder;
use App\Actions\Purchasing\ReceivePurchaseOrder;
use App\Models\Ingredient;
use App\Models\StockLot;
use App\Models\Supplier;
use App\Models\SupplierListing;
use App\Models\User;
use App\Models\Workspace;
use App\PurchaseOrderStatus;
use App\Services\ProductionBenchAccess;
use App\Services\StockPositionService;
use App\StockLotStatus;
use App\StockUnitKind;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

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
            'pack_description' => '5 kg pail',
            'unit_kind' => StockUnitKind::Mass,
            'canonical_quantity_per_pack' => '5000',
            'pack_price' => '50',
            'currency' => 'EUR',
        ]);
    SupplierListing::factory()
        ->for($workspace)
        ->for($supplier)
        ->for($ingredient)
        ->create([
            'supplier_sku' => 'OO-20',
            'pack_description' => '20 kg drum',
            'unit_kind' => StockUnitKind::Mass,
            'canonical_quantity_per_pack' => '20000',
            'pack_price' => '175',
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

    $fiveKg->update(['pack_price' => '65', 'canonical_quantity_per_pack' => '5100']);
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
        ->and($positionsAfterFirstReceipt['quarantined'])->toBe('4900.000000000')
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
        ->and($finalPositions['available'])->toBe('9700.000000000')
        ->and($finalPositions['incoming'])->toBe('0.000000000')
        ->and($finalPositions['forecast'])->toBe('9700.000000000');
});
