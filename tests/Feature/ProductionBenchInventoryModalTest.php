<?php

use App\Enums\StockLotOrigin;
use App\Livewire\ProductionBench\InventoryIndex;
use App\Models\Ingredient;
use App\Models\PackagingItem;
use App\Models\StockLot;
use App\Models\Supplier;
use App\Models\SupplierListing;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProductionBenchAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function inventoryModalWorkspace(string $currency = 'EUR'): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user, 'owner')->create([
        'default_currency' => $currency,
    ]);
    app(ProductionBenchAccess::class)->activate($user, $workspace);

    return [$user, $workspace];
}

function inventoryModalListing(Workspace $workspace, array $attributes = []): SupplierListing
{
    $supplier = Supplier::factory()->for($workspace)->create([
        'name' => 'Olive Supply',
    ]);

    return SupplierListing::factory()
        ->for($workspace)
        ->for($supplier)
        ->for(Ingredient::factory()->create(['display_name' => 'Olive oil']))
        ->create($attributes);
}

it('offers manual stock entry only from the Stock page', function (): void {
    [$user] = inventoryModalWorkspace();
    $this->actingAs($user);

    Livewire::test(InventoryIndex::class, ['mode' => 'overview'])
        ->assertDontSee(__('production_bench.inventory.add_stock_manually'))
        ->assertDontSee(__('production_bench.inventory.opening_stock'));

    Livewire::test(InventoryIndex::class, ['mode' => 'stock'])
        ->assertSee(__('production_bench.inventory.add_stock_manually'))
        ->assertActionExists('addStock');
});

it('uses the exact user-side button colors for Filament primary actions', function (): void {
    $styles = file_get_contents(resource_path('css/shared/filament-soapkraft.css'));

    expect($styles)
        ->toContain('[data-user-shell] .fi-btn.fi-color-primary:not(.fi-outlined)')
        ->toContain('background: var(--color-accent) !important;')
        ->toContain('color: var(--color-on-accent) !important;')
        ->toContain('[data-user-shell] .fi-btn.fi-color-primary:not(.fi-outlined):hover')
        ->toContain('background: var(--color-accent-hover) !important;');
});

it('opens an enhanced stock-entry modal with workspace defaults', function (): void {
    [$user, $workspace] = inventoryModalWorkspace('GBP');
    $this->actingAs($user);

    Livewire::test(InventoryIndex::class, ['mode' => 'stock'])
        ->mountAction('addStock')
        ->assertSchemaStateSet([
            'currency' => $workspace->default_currency,
            'stocked_at' => today()->toDateString(),
        ])
        ->assertMountedActionModalSee([
            __('production_bench.inventory.add_stock_manually'),
            __('production_bench.inventory.internal_batch_generated'),
            __('production_bench.inventory.supplier_batch'),
        ])
        ->assertMountedActionModalDontSeeHtml('type="date"');
});

it('accepts today and rejects future stocked-on dates', function (): void {
    [$user, $workspace] = inventoryModalWorkspace();
    $listing = inventoryModalListing($workspace);
    $this->actingAs($user);

    Livewire::test(InventoryIndex::class, ['mode' => 'stock'])
        ->callAction('addStock', data: [
            'supplier_listing_id' => $listing->id,
            'quantity' => '2',
            'unit' => 'kg',
            'price_per_unit' => '10',
            'currency' => $workspace->default_currency,
            'stocked_at' => today()->toDateString(),
        ])
        ->assertHasNoFormErrors();

    $component = Livewire::test(InventoryIndex::class, ['mode' => 'stock'])
        ->callAction('addStock', data: [
            'supplier_listing_id' => $listing->id,
            'quantity' => '2',
            'unit' => 'kg',
            'price_per_unit' => '10',
            'currency' => $workspace->default_currency,
            'stocked_at' => today()->addDay()->toDateString(),
        ])
        ->assertHasFormErrors(['stocked_at' => 'before_or_equal']);

    expect($component->errors()->first())->toBe(__('production_bench.inventory.stocked_on_future'))
        ->and(StockLot::query()->count())->toBe(1);
});

it('prefills the listing unit and cost when the currencies match', function (): void {
    [$user, $workspace] = inventoryModalWorkspace('EUR');
    $listing = inventoryModalListing($workspace, [
        'net_unit' => 'kg',
        'canonical_quantity_per_purchase_format' => '3000',
        'total_price' => '50',
        'currency' => 'EUR',
    ]);
    $this->actingAs($user);

    Livewire::test(InventoryIndex::class, ['mode' => 'stock'])
        ->mountAction('addStock')
        ->set('mountedActions.0.data.supplier_listing_id', $listing->id)
        ->assertSchemaStateSet([
            'unit' => 'kg',
            'price_per_unit' => '16.6667',
            'currency' => 'EUR',
        ]);
});

it('uses and locks the supplier listing currency when it differs from the workspace', function (): void {
    [$user, $workspace] = inventoryModalWorkspace('EUR');
    $listing = inventoryModalListing($workspace, [
        'canonical_quantity_per_purchase_format' => '5000',
        'total_price' => '50',
        'currency' => 'USD',
    ]);
    $this->actingAs($user);

    Livewire::test(InventoryIndex::class, ['mode' => 'stock'])
        ->mountAction('addStock')
        ->set('mountedActions.0.data.supplier_listing_id', $listing->id)
        ->assertFormFieldDisabled('currency')
        ->assertSchemaStateSet([
            'unit' => 'kg',
            'price_per_unit' => '10.00',
            'currency' => 'USD',
        ]);
});

it('locks the quantity unit defined by the supplier listing', function (): void {
    [$user, $workspace] = inventoryModalWorkspace('EUR');
    $listing = inventoryModalListing($workspace, [
        'canonical_quantity_per_purchase_format' => '5000',
        'total_price' => '50',
        'currency' => 'EUR',
    ]);
    $this->actingAs($user);

    Livewire::test(InventoryIndex::class, ['mode' => 'stock'])
        ->mountAction('addStock')
        ->set('mountedActions.0.data.supplier_listing_id', $listing->id)
        ->assertFormFieldDisabled('unit')
        ->assertSchemaStateSet([
            'unit' => 'kg',
            'price_per_unit' => '10.00',
        ]);
});

it('replaces a preselected currency when the supplier listing is selected', function (): void {
    [$user, $workspace] = inventoryModalWorkspace('EUR');
    $listing = inventoryModalListing($workspace, [
        'canonical_quantity_per_purchase_format' => '5000',
        'total_price' => '50',
        'currency' => 'USD',
    ]);
    $this->actingAs($user);

    Livewire::test(InventoryIndex::class, ['mode' => 'stock'])
        ->mountAction('addStock')
        ->set('mountedActions.0.data.currency', 'GBP')
        ->set('mountedActions.0.data.supplier_listing_id', $listing->id)
        ->assertFormFieldDisabled('currency')
        ->assertSchemaStateSet([
            'currency' => 'USD',
            'price_per_unit' => '10.00',
        ]);
});

it('searches only active supplier listings from the current workspace', function (): void {
    [$user, $workspace] = inventoryModalWorkspace();
    $matchingListing = inventoryModalListing($workspace, [
        'supplier_sku' => 'OLIVE-25',
        'purchase_format' => '25 kg drum',
    ]);
    inventoryModalListing($workspace, [
        'is_active' => false,
        'supplier_sku' => 'OLIVE-INACTIVE',
    ]);
    [, $foreignWorkspace] = inventoryModalWorkspace();
    inventoryModalListing($foreignWorkspace, [
        'supplier_sku' => 'OLIVE-FOREIGN',
    ]);
    $this->actingAs($user);

    $results = Livewire::test(InventoryIndex::class, ['mode' => 'stock'])
        ->instance()
        ->supplierListingSearchResults('OLIVE');

    expect($results)
        ->toHaveKey($matchingListing->id)
        ->toHaveCount(1);
});

it('finds packaging supplier listings by internal material code and shows the code in the option label', function (): void {
    [$user, $workspace] = inventoryModalWorkspace();
    $supplier = Supplier::factory()->for($workspace)->create(['name' => 'Bottle Supply']);
    $packagingItem = PackagingItem::factory()->for($workspace)->create([
        'name' => 'Clear 250 ml bottle',
        'material_code' => 'PK-BOT-250',
    ]);
    $listing = SupplierListing::factory()
        ->for($workspace)
        ->for($supplier)
        ->state([
            'ingredient_id' => null,
            'packaging_item_id' => $packagingItem->id,
            'supplier_sku' => '123645',
            'unit_kind' => 'count',
            'net_quantity' => '100',
            'net_unit' => 'count',
            'canonical_quantity_per_purchase_format' => '100',
        ])
        ->create();
    $this->actingAs($user);

    $component = Livewire::test(InventoryIndex::class, ['mode' => 'stock']);

    expect($component->instance()->supplierListingSearchResults('PK-BOT-250'))
        ->toBe([$listing->id => 'PK-BOT-250 · Clear 250 ml bottle · Bottle Supply · 123645 · '.$listing->purchase_format])
        ->and($component->instance()->supplierListingOptionLabel($listing->id))
        ->toContain('PK-BOT-250');
});

it('creates a stock lot from the modal with an automatic internal batch number', function (): void {
    [$user, $workspace] = inventoryModalWorkspace();
    $listing = inventoryModalListing($workspace, [
        'net_unit' => 'kg',
        'canonical_quantity_per_purchase_format' => '5000',
        'total_price' => '50',
        'currency' => 'USD',
    ]);
    $this->actingAs($user);

    Livewire::test(InventoryIndex::class, ['mode' => 'stock'])
        ->callAction('addStock', data: [
            'supplier_listing_id' => $listing->id,
            'quantity' => '2.5',
            'unit' => 'kg',
            'price_per_unit' => '11.25',
            'currency' => $workspace->default_currency,
            'supplier_batch_number' => 'SUP-2026-42',
            'stocked_at' => today()->toDateString(),
            'expires_at' => today()->addYear()->toDateString(),
            'notes' => 'Stock already on hand',
        ])
        ->assertHasNoFormErrors();

    $lot = StockLot::query()->sole();

    expect($lot->origin)->toBe(StockLotOrigin::OpeningBalance)
        ->and($lot->supplier_listing_id)->toBe($listing->id)
        ->and($lot->supplier_batch_number)->toBe('SUP-2026-42')
        ->and($lot->internal_lot_code)->toMatch('/^SK-\d{6}-\d{4}$/')
        ->and($lot->historical_unit_cost)->toBe('0.011250000')
        ->and($lot->currency)->toBe($listing->currency)
        ->and($lot->movements()->sole()->original_quantity)->toBe('2.500000000')
        ->and($lot->movements()->sole()->original_unit)->toBe('kg');
});

it('rejects an expiry date before the stock date', function (): void {
    [$user, $workspace] = inventoryModalWorkspace();
    $listing = inventoryModalListing($workspace);
    $this->actingAs($user);

    Livewire::test(InventoryIndex::class, ['mode' => 'stock'])
        ->callAction('addStock', data: [
            'supplier_listing_id' => $listing->id,
            'quantity' => '2',
            'unit' => 'kg',
            'price_per_unit' => '10',
            'currency' => $workspace->default_currency,
            'stocked_at' => today()->toDateString(),
            'expires_at' => today()->subDay()->toDateString(),
        ])
        ->assertHasFormErrors(['expires_at']);

    expect(StockLot::query()->count())->toBe(0);
});
