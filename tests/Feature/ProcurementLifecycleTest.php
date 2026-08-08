<?php

use App\Actions\Purchasing\ConvertQuotationToPurchaseOrder;
use App\Actions\Purchasing\CreatePurchaseOrder;
use App\Actions\Purchasing\IssueQuotationRequest;
use App\Actions\Purchasing\PlacePurchaseOrder;
use App\Actions\Purchasing\RecordProcurementLinePrice;
use App\Enums\ListingPriceBasis;
use App\Enums\MaterialPriceSource;
use App\Enums\ProcurementStage;
use App\Enums\PurchaseOrderStatus;
use App\Models\CurrentMaterialPrice;
use App\Models\Ingredient;
use App\Models\Supplier;
use App\Models\SupplierListing;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProductionBenchAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('stores the quotation to purchase order lifecycle and immutable snapshots', function (): void {
    expect(Schema::hasColumns('purchase_orders', [
        'stage',
        'quotation_reference',
        'quotation_requested_at',
        'quotation_snapshot',
        'price_confirmed_at',
        'issued_at',
        'purchase_order_snapshot',
        'supplier_snapshot',
        'delivery_address_snapshot',
        'shipping_amount',
        'discount_amount',
        'tax_amount',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('purchase_order_lines', [
            'supplier_item_name',
            'price_basis',
            'price_amount',
            'price_unit',
            'price_recorded_at',
        ]))->toBeTrue();
});

it('defines quotation and purchase order procurement stages', function (): void {
    expect(enum_exists(ProcurementStage::class))->toBeTrue();
});

it('creates a price-free quotation draft from selected supplier listings', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($owner, $workspace);
    $supplier = Supplier::factory()->for($workspace)->create(['default_currency' => 'EUR']);
    $listing = SupplierListing::factory()
        ->for($workspace)
        ->for($supplier)
        ->for(Ingredient::factory())
        ->create(['currency' => 'EUR', 'total_price' => '52.50']);

    $quotation = app(CreatePurchaseOrder::class)->handle(
        actor: $owner,
        workspace: $workspace,
        supplier: $supplier,
        lines: [['listing' => $listing, 'packs' => 2]],
        stage: ProcurementStage::Quotation,
    );
    $line = $quotation->lines()->sole();

    expect($quotation->stage)->toBe(ProcurementStage::Quotation)
        ->and($quotation->status)->toBe(PurchaseOrderStatus::Draft)
        ->and($line->supplier_listing_id)->toBe($listing->id)
        ->and($line->ordered_packs)->toBe(2)
        ->and($line->pack_price)->toBeNull()
        ->and($line->expected_cost)->toBeNull();
});

it('issues an immutable price-free quotation snapshot', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create(['name' => 'Atelier Savon']);
    app(ProductionBenchAccess::class)->activate($owner, $workspace);
    $supplier = Supplier::factory()->for($workspace)->create([
        'code' => 'OILS-01',
        'name' => 'Original Oils',
        'email' => 'orders@original.test',
    ]);
    $listing = SupplierListing::factory()
        ->for($workspace)
        ->for($supplier)
        ->for(Ingredient::factory()->create(['display_name' => 'Olive oil']))
        ->create([
            'supplier_sku' => 'OO-5',
            'purchase_format' => '5 kg pail',
            'currency' => 'EUR',
            'total_price' => '52.50',
        ]);
    $quotation = app(CreatePurchaseOrder::class)->handle(
        actor: $owner,
        workspace: $workspace,
        supplier: $supplier,
        lines: [['listing' => $listing, 'packs' => 3]],
        stage: ProcurementStage::Quotation,
    );

    $issued = app(IssueQuotationRequest::class)->handle($owner, $quotation);
    $snapshot = $issued->quotation_snapshot;

    expect($issued->quotation_reference)->toStartWith('RFQ-')
        ->and($issued->quotation_requested_at)->not->toBeNull()
        ->and($snapshot['supplier']['code'])->toBe('OILS-01')
        ->and($snapshot['supplier']['name'])->toBe('Original Oils')
        ->and($snapshot['lines'][0]['catalogue_name'])->toBe('Olive oil')
        ->and($snapshot['lines'][0]['purchase_format'])->toBe('5 kg pail')
        ->and($snapshot['lines'][0]['price'])->toBeNull();

    $supplier->update(['name' => 'Renamed Oils']);
    $listing->update(['purchase_format' => '10 kg drum']);

    expect($issued->refresh()->quotation_snapshot)->toBe($snapshot);
});

it('records a confirmed quotation price and converts the same document and lines', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($owner, $workspace);
    $supplier = Supplier::factory()->for($workspace)->create(['default_currency' => 'EUR']);
    $ingredient = Ingredient::factory()->create(['display_name' => 'Olive oil']);
    $listing = SupplierListing::factory()
        ->for($workspace)
        ->for($supplier)
        ->for($ingredient)
        ->create([
            'canonical_quantity_per_purchase_format' => '5000',
            'net_quantity' => '5',
            'net_unit' => 'kg',
            'currency' => 'EUR',
        ]);
    $quotation = app(CreatePurchaseOrder::class)->handle(
        actor: $owner,
        workspace: $workspace,
        supplier: $supplier,
        lines: [['listing' => $listing, 'packs' => 2]],
        stage: ProcurementStage::Quotation,
    );
    $quotation = app(IssueQuotationRequest::class)->handle($owner, $quotation);
    $lineId = $quotation->lines()->sole()->id;

    app(RecordProcurementLinePrice::class)->handle(
        actor: $owner,
        line: $quotation->lines()->sole(),
        basis: ListingPriceBasis::PerUnit,
        amount: '9.80',
        unit: 'kg',
    );
    $converted = app(ConvertQuotationToPurchaseOrder::class)->handle($owner, $quotation);
    $line = $converted->lines()->sole();

    expect($converted->id)->toBe($quotation->id)
        ->and($converted->stage)->toBe(ProcurementStage::PurchaseOrder)
        ->and($converted->price_confirmed_at)->not->toBeNull()
        ->and($line->id)->toBe($lineId)
        ->and($line->pack_price)->toBe('49.000000000')
        ->and($line->expected_cost)->toBe('98.000000000')
        ->and($listing->refresh()->price_amount)->toBe('9.800000000')
        ->and($listing->price_unit)->toBe('kg')
        ->and($listing->total_price)->toBe('49.000000000')
        ->and(CurrentMaterialPrice::query()
            ->where('workspace_id', $workspace->id)
            ->where('ingredient_id', $ingredient->id)
            ->value('price_per_canonical_unit'))->toBe('0.009800000000');
});

it('converts an issued price-free quotation while preserving its lines', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($owner, $workspace);
    $supplier = Supplier::factory()->for($workspace)->create(['default_currency' => 'EUR']);
    $listing = SupplierListing::factory()
        ->for($workspace)
        ->for($supplier)
        ->for(Ingredient::factory())
        ->create(['currency' => 'EUR']);
    $quotation = app(CreatePurchaseOrder::class)->handle(
        actor: $owner,
        workspace: $workspace,
        supplier: $supplier,
        lines: [['listing' => $listing, 'packs' => 2]],
        stage: ProcurementStage::Quotation,
    );
    $quotation = app(IssueQuotationRequest::class)->handle($owner, $quotation);
    $lineId = $quotation->lines()->sole()->id;

    $converted = app(ConvertQuotationToPurchaseOrder::class)->handle($owner, $quotation);

    expect($converted->stage)->toBe(ProcurementStage::PurchaseOrder)
        ->and($converted->price_confirmed_at)->toBeNull()
        ->and($converted->lines()->sole()->id)->toBe($lineId)
        ->and($converted->lines()->sole()->pack_price)->toBeNull();
});

it('records the confirmed price after a price-free quotation becomes a purchase order', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($owner, $workspace);
    $supplier = Supplier::factory()->for($workspace)->create(['default_currency' => 'EUR']);
    $listing = SupplierListing::factory()
        ->for($workspace)
        ->for($supplier)
        ->for(Ingredient::factory())
        ->create([
            'canonical_quantity_per_purchase_format' => '5000',
            'currency' => 'EUR',
        ]);
    $quotation = app(CreatePurchaseOrder::class)->handle(
        actor: $owner,
        workspace: $workspace,
        supplier: $supplier,
        lines: [['listing' => $listing, 'packs' => 2]],
        stage: ProcurementStage::Quotation,
    );
    $quotation = app(IssueQuotationRequest::class)->handle($owner, $quotation);
    $order = app(ConvertQuotationToPurchaseOrder::class)->handle($owner, $quotation);

    $line = app(RecordProcurementLinePrice::class)->handle(
        actor: $owner,
        line: $order->lines()->sole(),
        basis: ListingPriceBasis::PerUnit,
        amount: '9.80',
        unit: 'kg',
    );

    expect($line->pack_price)->toBe('49.000000000')
        ->and($order->refresh()->price_confirmed_at)->not->toBeNull();
});

it('issues an immutable priced purchase order snapshot from a direct draft', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create(['name' => 'Atelier Savon']);
    app(ProductionBenchAccess::class)->activate($owner, $workspace);
    $supplier = Supplier::factory()->for($workspace)->create([
        'code' => 'PACK-01',
        'name' => 'Original Packaging',
    ]);
    $listing = SupplierListing::factory()
        ->for($workspace)
        ->for($supplier)
        ->for(Ingredient::factory()->create(['display_name' => 'Coconut oil']))
        ->create([
            'supplier_item_name' => 'Organic coconut oil RBD',
            'purchase_format' => '20 kg drum',
            'price_basis' => ListingPriceBasis::PerUnit,
            'price_amount' => '8',
            'price_unit' => 'kg',
            'total_price' => '160',
            'currency' => 'EUR',
        ]);
    $order = app(CreatePurchaseOrder::class)->handle(
        actor: $owner,
        workspace: $workspace,
        supplier: $supplier,
        lines: [['listing' => $listing, 'packs' => 2]],
    );

    $issued = app(PlacePurchaseOrder::class)->handle(
        actor: $owner,
        order: $order,
        deliveryAddress: ['name' => 'Atelier Savon', 'city' => 'Lyon', 'country_code' => 'FR'],
        shippingAmount: '12.50',
        discountAmount: '5.00',
        taxAmount: '33.50',
    );
    $snapshot = $issued->purchase_order_snapshot;

    expect($issued->status)->toBe(PurchaseOrderStatus::Ordered)
        ->and($issued->issued_at)->not->toBeNull()
        ->and($issued->supplier_snapshot['name'])->toBe('Original Packaging')
        ->and($issued->delivery_address_snapshot['city'])->toBe('Lyon')
        ->and($issued->lines()->sole()->supplier_item_name)->toBe('Organic coconut oil RBD')
        ->and($issued->lines()->sole()->price_basis)->toBe(ListingPriceBasis::PerUnit)
        ->and($issued->lines()->sole()->price_amount)->toBe('8.000000000')
        ->and($snapshot['lines'][0]['price'])->toBe('160.000000000')
        ->and($snapshot['subtotal'])->toBe('320.000000000')
        ->and($snapshot['total'])->toBe('361.000000000')
        ->and(CurrentMaterialPrice::query()
            ->where('workspace_id', $workspace->id)
            ->where('ingredient_id', $listing->ingredient_id)
            ->value('source_type'))->toBe(MaterialPriceSource::ProcurementDocument);

    $supplier->update(['name' => 'Renamed Packaging']);
    $listing->update(['total_price' => '190']);

    expect($issued->refresh()->purchase_order_snapshot)->toBe($snapshot);
});
