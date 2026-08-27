<?php

use App\Actions\Purchasing\CreatePurchaseOrder;
use App\Actions\Purchasing\IssueQuotationRequest;
use App\Actions\Purchasing\RecordProcurementLinePrice;
use App\Enums\ListingPriceBasis;
use App\Enums\ProcurementStage;
use App\Enums\StockUnitKind;
use App\Livewire\ProductionBench\Purchasing\ProcurementCreate;
use App\Livewire\ProductionBench\Purchasing\ProcurementDetail;
use App\Models\Ingredient;
use App\Models\PackagingItem;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierListing;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProductionBenchAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('provides separate quotation request and purchase order pages', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($owner, $workspace);

    $this->actingAs($owner)
        ->get(route('production-bench.purchasing.quotations'))
        ->assertOk()
        ->assertSee('Quotation requests');

    $this->actingAs($owner)
        ->get(route('production-bench.purchasing.orders'))
        ->assertOk()
        ->assertSee('Purchase orders');
});

it('creates a quotation draft from selected supplier listings in the customer workflow', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($owner, $workspace);
    $supplier = Supplier::factory()->for($workspace)->create();
    $listing = SupplierListing::factory()
        ->for($workspace)
        ->for($supplier)
        ->for(Ingredient::factory())
        ->create();
    $this->actingAs($owner);

    Livewire::test(ProcurementCreate::class, ['stage' => ProcurementStage::Quotation->value])
        ->set('supplierId', $supplier->id)
        ->set("packs.{$listing->id}", 2)
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    $order = PurchaseOrder::query()->sole();

    expect($order->stage)->toBe(ProcurementStage::Quotation)
        ->and($order->lines()->sole()->ordered_packs)->toBe(2)
        ->and($order->lines()->sole()->pack_price)->toBeNull();

    $this->get(route('production-bench.purchasing.procurement.show', $order))
        ->assertOk()
        ->assertSee($supplier->name)
        ->assertSee('Quotation request');
});

it('shows listing currencies and a visible error when selected quotation lines mix currencies', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($owner, $workspace);
    $supplier = Supplier::factory()->for($workspace)->create();
    $ingredientListing = SupplierListing::factory()
        ->for($workspace)
        ->for($supplier)
        ->for(Ingredient::factory())
        ->create(['currency' => 'USD']);
    $packagingItem = PackagingItem::factory()->for($workspace)->create();
    $packagingListing = SupplierListing::factory()
        ->for($workspace)
        ->for($supplier)
        ->state([
            'ingredient_id' => null,
            'packaging_item_id' => $packagingItem->id,
            'unit_kind' => StockUnitKind::Count,
            'net_quantity' => '100',
            'net_unit' => 'count',
            'canonical_quantity_per_purchase_format' => '100',
            'currency' => 'EUR',
        ])
        ->create();
    $this->actingAs($owner);

    Livewire::test(ProcurementCreate::class, ['stage' => ProcurementStage::Quotation->value])
        ->set('supplierId', $supplier->id)
        ->assertSee('USD')
        ->assertSee('EUR')
        ->set("packs.{$ingredientListing->id}", 1)
        ->set("packs.{$packagingListing->id}", 1)
        ->call('save')
        ->assertHasErrors('packs')
        ->assertSee('same currency');

    expect(PurchaseOrder::query()->count())->toBe(0);
});

it('shows packaging material codes separately from supplier SKUs when choosing procurement lines', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($owner, $workspace);
    $supplier = Supplier::factory()->for($workspace)->create();
    $packagingItem = PackagingItem::factory()->for($workspace)->create([
        'name' => 'Clear 250 ml bottle',
        'material_code' => 'PK-BOT-250',
    ]);
    SupplierListing::factory()
        ->for($workspace)
        ->for($supplier)
        ->state([
            'ingredient_id' => null,
            'packaging_item_id' => $packagingItem->id,
            'supplier_sku' => '123645',
            'unit_kind' => StockUnitKind::Count,
            'net_quantity' => '100',
            'net_unit' => 'count',
            'canonical_quantity_per_purchase_format' => '100',
            'currency' => 'EUR',
        ])
        ->create();
    $this->actingAs($owner);

    Livewire::test(ProcurementCreate::class, ['stage' => ProcurementStage::Quotation->value])
        ->set('supplierId', $supplier->id)
        ->assertSee('Clear 250 ml bottle')
        ->assertSee('PK-BOT-250')
        ->assertSee('123645');
});

it('saves ingredient and packaging quotation lines when their supplier currency matches', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($owner, $workspace);
    $supplier = Supplier::factory()->for($workspace)->create(['default_currency' => 'EUR']);
    $ingredientListing = SupplierListing::factory()
        ->for($workspace)
        ->for($supplier)
        ->for(Ingredient::factory())
        ->create(['currency' => 'EUR']);
    $packagingItem = PackagingItem::factory()->for($workspace)->create();
    $packagingListing = SupplierListing::factory()
        ->for($workspace)
        ->for($supplier)
        ->state([
            'ingredient_id' => null,
            'packaging_item_id' => $packagingItem->id,
            'unit_kind' => StockUnitKind::Count,
            'net_quantity' => '100',
            'net_unit' => 'count',
            'canonical_quantity_per_purchase_format' => '100',
            'currency' => 'EUR',
        ])
        ->create();
    $this->actingAs($owner);

    Livewire::test(ProcurementCreate::class, ['stage' => ProcurementStage::Quotation->value])
        ->set('supplierId', $supplier->id)
        ->set("packs.{$ingredientListing->id}", 1)
        ->set("packs.{$packagingListing->id}", 2)
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    $quotation = PurchaseOrder::query()->sole();

    expect($quotation->currency)->toBe('EUR')
        ->and($quotation->lines)->toHaveCount(2)
        ->and($quotation->lines->pluck('supplier_listing_id')->all())
        ->toContain($ingredientListing->id, $packagingListing->id);
});

it('issues prices converts and places a quotation through the customer workflow', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($owner, $workspace);
    $supplier = Supplier::factory()->for($workspace)->create();
    $listing = SupplierListing::factory()
        ->for($workspace)
        ->for($supplier)
        ->for(Ingredient::factory())
        ->create(['canonical_quantity_per_purchase_format' => '5000']);
    $quotation = app(CreatePurchaseOrder::class)->handle(
        actor: $owner,
        workspace: $workspace,
        supplier: $supplier,
        lines: [['listing' => $listing, 'packs' => 2]],
        stage: ProcurementStage::Quotation,
    );
    $line = $quotation->lines()->sole();
    $this->actingAs($owner);

    Livewire::test(ProcurementDetail::class, ['purchaseOrder' => $quotation->public_id])
        ->call('issueQuotation')
        ->assertHasNoErrors()
        ->assertSee('Review each requested item')
        ->set("priceInputs.{$line->id}.basis", ListingPriceBasis::PerUnit->value)
        ->set("priceInputs.{$line->id}.amount", '9.80')
        ->set("priceInputs.{$line->id}.unit", 'kg')
        ->call('recordPrice', $line->id)
        ->assertHasNoErrors()
        ->call('convertToPurchaseOrder')
        ->assertHasNoErrors()
        ->call('issuePurchaseOrder')
        ->assertHasNoErrors()
        ->assertSee('Purchase order');

    Livewire::test(ProcurementDetail::class, ['purchaseOrder' => $quotation->public_id])
        ->assertSee('49.00 EUR')
        ->assertSeeHtml('grid-cols-[minmax(0,1.15fr)_minmax(10rem,1fr)_7rem_minmax(12.5rem,1.15fr)]');

    expect($quotation->refresh()->stage)->toBe(ProcurementStage::PurchaseOrder)
        ->and($quotation->issued_at)->not->toBeNull()
        ->and($quotation->purchase_order_snapshot)->not->toBeNull();
});

it('creates a purchase order by selecting a previously priced quotation request', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($owner, $workspace);
    $supplier = Supplier::factory()->for($workspace)->create();
    $listing = SupplierListing::factory()
        ->for($workspace)
        ->for($supplier)
        ->for(Ingredient::factory())
        ->create(['canonical_quantity_per_purchase_format' => '5000']);
    $quotation = app(CreatePurchaseOrder::class)->handle(
        actor: $owner,
        workspace: $workspace,
        supplier: $supplier,
        lines: [['listing' => $listing, 'packs' => 2]],
        stage: ProcurementStage::Quotation,
    );
    $quotation = app(IssueQuotationRequest::class)->handle($owner, $quotation);
    $quotationLine = $quotation->lines()->sole();
    app(RecordProcurementLinePrice::class)->handle(
        actor: $owner,
        line: $quotationLine,
        basis: ListingPriceBasis::PerUnit,
        amount: '9.80',
        unit: 'kg',
    );
    $this->actingAs($owner);

    Livewire::test(ProcurementCreate::class, ['stage' => ProcurementStage::PurchaseOrder->value])
        ->assertSee($quotation->quotation_reference)
        ->set('quotationRequestPublicId', $quotation->public_id)
        ->call('useQuotationRequest')
        ->assertHasNoErrors()
        ->assertRedirect();

    expect(PurchaseOrder::query()->count())->toBe(1)
        ->and($quotation->refresh()->stage)->toBe(ProcurementStage::PurchaseOrder)
        ->and($quotation->lines()->sole()->id)->toBe($quotationLine->id);

    Livewire::test(ProcurementDetail::class, ['purchaseOrder' => $quotation->public_id])
        ->assertSet("priceInputs.{$quotationLine->id}.amount", '9.80');
});

it('creates a purchase order by selecting an issued price-free quotation request', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($owner, $workspace);
    $supplier = Supplier::factory()->for($workspace)->create();
    $listing = SupplierListing::factory()
        ->for($workspace)
        ->for($supplier)
        ->for(Ingredient::factory())
        ->create([
            'purchase_format' => '1 lb bottle',
            'net_quantity' => '1',
            'net_unit' => 'lb',
            'canonical_quantity_per_purchase_format' => '453.592',
        ]);
    $quotation = app(CreatePurchaseOrder::class)->handle(
        actor: $owner,
        workspace: $workspace,
        supplier: $supplier,
        lines: [['listing' => $listing, 'packs' => 2]],
        stage: ProcurementStage::Quotation,
    );
    $quotation = app(IssueQuotationRequest::class)->handle($owner, $quotation);
    $quotationLineId = $quotation->lines()->sole()->id;
    $this->actingAs($owner);

    Livewire::test(ProcurementDetail::class, ['purchaseOrder' => $quotation->public_id])
        ->assertSee('Some requested items have no price yet.')
        ->assertSeeHtml('data-production-bench-convert-warning')
        ->assertSet("priceInputs.{$quotationLineId}.unit", 'lb')
        ->set("priceInputs.{$quotationLineId}.basis", ListingPriceBasis::TotalPurchaseFormat->value)
        ->assertSet("priceInputs.{$quotationLineId}.unit", null)
        ->set("priceInputs.{$quotationLineId}.basis", ListingPriceBasis::PerUnit->value)
        ->assertSet("priceInputs.{$quotationLineId}.unit", 'lb');

    Livewire::test(ProcurementCreate::class, ['stage' => ProcurementStage::PurchaseOrder->value])
        ->assertSee($quotation->quotation_reference)
        ->assertDontSeeHtml('value="'.$quotation->public_id.'" disabled')
        ->set('quotationRequestPublicId', $quotation->public_id)
        ->call('useQuotationRequest')
        ->assertHasNoErrors()
        ->assertRedirect();

    expect($quotation->refresh()->stage)->toBe(ProcurementStage::PurchaseOrder)
        ->and($quotation->price_confirmed_at)->toBeNull()
        ->and($quotation->lines()->sole()->id)->toBe($quotationLineId);

    Livewire::test(ProcurementDetail::class, ['purchaseOrder' => $quotation->public_id])
        ->assertSee('Confirm a price for every line before issuing this purchase order.')
        ->assertSee('Price')
        ->assertDontSee('Supplier price')
        ->assertSee('Save price')
        ->assertSeeHtml('class="sk-btn sk-btn-primary py-2"')
        ->assertSeeHtml('data-production-bench-price-field')
        ->assertSeeHtml('data-production-bench-price-currency')
        ->assertDontSee('Issue purchase order')
        ->assertSeeHtml('data-production-bench-price-amount')
        ->assertSeeHtml('pattern="[0-9]+([.,][0-9]+)?"')
        ->set("priceInputs.{$quotationLineId}.amount", 'letters')
        ->call('recordPrice', $quotationLineId)
        ->assertHasErrors("priceInputs.{$quotationLineId}.amount")
        ->set("priceInputs.{$quotationLineId}.basis", ListingPriceBasis::PerUnit->value)
        ->set("priceInputs.{$quotationLineId}.amount", '9,80')
        ->set("priceInputs.{$quotationLineId}.unit", 'kg')
        ->call('recordPrice', $quotationLineId)
        ->assertHasNoErrors()
        ->assertDontSee('Confirm a price for every line before issuing this purchase order.')
        ->assertSee('Issue purchase order');
});

it('does not expose or convert another workspaces quotation request', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($owner, $workspace);
    $otherOwner = User::factory()->create();
    $otherWorkspace = Workspace::factory()->for($otherOwner, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($otherOwner, $otherWorkspace);
    $supplier = Supplier::factory()->for($otherWorkspace)->create();
    $listing = SupplierListing::factory()
        ->for($otherWorkspace)
        ->for($supplier)
        ->for(Ingredient::factory())
        ->create();
    $quotation = app(CreatePurchaseOrder::class)->handle(
        actor: $otherOwner,
        workspace: $otherWorkspace,
        supplier: $supplier,
        lines: [['listing' => $listing, 'packs' => 1]],
        stage: ProcurementStage::Quotation,
    );
    $quotation = app(IssueQuotationRequest::class)->handle($otherOwner, $quotation);
    $this->actingAs($owner);

    Livewire::test(ProcurementCreate::class, ['stage' => ProcurementStage::PurchaseOrder->value])
        ->assertDontSee($quotation->quotation_reference)
        ->set('quotationRequestPublicId', $quotation->public_id)
        ->call('useQuotationRequest')
        ->assertHasErrors('quotationRequestPublicId')
        ->assertNoRedirect();

    expect($quotation->refresh()->stage)->toBe(ProcurementStage::Quotation);
});
