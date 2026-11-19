<?php

use App\Actions\Inventory\SaveProductSizeSku;
use App\Enums\NominalContentUnit;
use App\Enums\StockLotOrigin;
use App\Enums\StockLotStatus;
use App\Livewire\ProductionBench\InventoryIndex;
use App\Models\Brand;
use App\Models\Ingredient;
use App\Models\PackagingItem;
use App\Models\ProductCollection;
use App\Models\ProductSize;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierListing;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProductionBenchAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
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

it('reports finished stock by size including archived and unrecorded sizes', function (): void {
    [$user, $workspace] = inventoryModalWorkspace();
    $recipe = Recipe::factory()->for($workspace)->create(['name' => 'Turmeric soap']);
    $large = ProductSize::factory()->for($recipe)->create(['name' => '100 g bar', 'archived_at' => now()]);
    $small = ProductSize::factory()->for($recipe)->create(['name' => '50 g bar', 'declared_content_value' => '50']);
    $otherWorkspace = Workspace::factory()->create();
    $otherRecipe = Recipe::factory()->for($otherWorkspace)->create(['name' => 'Other workspace soap']);

    $createLot = function (Recipe $product, ?ProductSize $size, StockLotStatus $status, array $deltas): void {
        $lot = StockLot::factory()->forRecipe()->create([
            'workspace_id' => $product->workspace_id,
            'recipe_id' => $product->id,
            'product_size_id' => $size?->id,
            'product_size_label_snapshot' => $size?->name,
            'origin' => StockLotOrigin::ProductionOutput,
            'status' => $status,
        ]);
        foreach ($deltas as $delta) {
            StockMovement::factory()->for($lot)->create(['workspace_id' => $product->workspace_id, 'quantity_delta' => $delta]);
        }
    };

    $createLot($recipe, $large, StockLotStatus::Released, ['40', '-3']);
    $createLot($recipe, $large, StockLotStatus::Released, ['7']);
    $createLot($recipe, $small, StockLotStatus::Quarantined, ['19']);
    $createLot($recipe, null, StockLotStatus::Released, ['2']);
    $createLot($otherRecipe, null, StockLotStatus::Released, ['70']);

    $register = Livewire::actingAs($user)->test(InventoryIndex::class, ['mode' => 'stock'])
        ->assertSee(__('production_bench.inventory.finished_stock_by_size'))
        ->assertSee('100 g bar')
        ->assertSee('50 g bar')
        ->assertSee(__('production_bench.inventory.size_not_recorded'))
        ->assertDontSee('Other workspace soap');

    $balances = $register->viewData('finishedStock')->keyBy('product_size_id');
    expect($balances)->toHaveCount(3)
        ->and((int) $balances[$large->id]->available)->toBe(44)
        ->and((int) $balances[$small->id]->available)->toBe(0)
        ->and((int) $balances[$small->id]->quarantined)->toBe(19)
        ->and((int) $balances['']->available)->toBe(2);

    $published = RecipeVersion::factory()->for($recipe)->for($workspace)->create(['is_current' => false]);
    $published->productSizes()->attach($small);
    $catalogue = Livewire::test(InventoryIndex::class, ['mode' => 'finished-products']);
    $balances = $catalogue->viewData('finishedStock')->keyBy('product_size_id');
    expect($balances)->toHaveCount(3)
        ->and((int) $balances[$small->id]->quarantined)->toBe(19)
        ->and((int) $balances[$large->id]->available)->toBe(44);
});

it('lists the latest published sizes at zero stock before the first run', function (): void {
    [$user, $workspace] = inventoryModalWorkspace();
    $recipe = Recipe::factory()->for($workspace)->create(['name' => 'Turmeric soap']);
    $old = ProductSize::factory()->for($recipe)->create(['name' => 'Old format']);
    $published = ProductSize::factory()->for($recipe)->create(['name' => '50 g bar']);
    $draft = ProductSize::factory()->for($recipe)->create(['name' => 'Draft format']);
    foreach ([$old, $published, $draft] as $index => $size) {
        $version = RecipeVersion::factory()->for($recipe)->for($workspace)->create([
            'version_number' => $index + 1,
            'is_current' => $size->is($draft),
        ]);
        $version->productSizes()->attach($size);
    }

    $this->actingAs($user)->get(route('production-bench.inventory.finished-products'))
        ->assertOk()
        ->assertSee('Finished products')
        ->assertSee('Turmeric soap')
        ->assertSee('50 g bar')
        ->assertDontSee('Old format')
        ->assertDontSee('Draft format');

    $page = Livewire::test(InventoryIndex::class, ['mode' => 'finished-products'])
        ->set('finishedPerPage', 10)
        ->assertSee('50 g bar');
    $balance = $page->viewData('finishedStock')->sole();
    expect($balance->product_size_id)->toBe($published->id)
        ->and((int) $balance->available)->toBe(0)
        ->and((int) $balance->quarantined)->toBe(0);
});

it('edits and searches finished product SKUs and filters inherited organization', function (): void {
    [$user, $workspace] = inventoryModalWorkspace();
    $brand = Brand::factory()->for($workspace)->create(['name' => 'Workshop']);
    $collection = ProductCollection::factory()->for($brand)->create(['name' => 'Signature']);
    $otherCollection = ProductCollection::factory()->for($brand)->create(['name' => 'Everyday']);
    $recipe = Recipe::factory()->for($workspace)->create([
        'name' => 'Turmeric soap',
        'brand_id' => $brand->id,
        'product_collection_id' => $collection->id,
    ]);
    $mint = Recipe::factory()->for($workspace)->create([
        'name' => 'Mint cleanser',
        'brand_id' => $brand->id,
        'product_collection_id' => $otherCollection->id,
    ]);
    $soapSize = ProductSize::factory()->for($recipe)->create();
    $mintSize = ProductSize::factory()->for($mint)->create([
        'name' => 'Travel bottle',
        'declared_content_value' => '30',
        'declared_content_unit' => NominalContentUnit::Millilitre,
    ]);
    foreach ([[$recipe, $soapSize], [$mint, $mintSize]] as [$product, $size]) {
        RecipeVersion::factory()->for($product)->for($workspace)->create(['is_current' => false])
            ->productSizes()->attach($size);
    }

    $historyUrl = route('production-bench.production.index', ['date_basis' => 'manufactured', 'size' => $soapSize->public_id]);
    $page = Livewire::actingAs($user)->test(InventoryIndex::class, ['mode' => 'finished-products'])
        ->assertSeeHtml(e($historyUrl))
        ->assertSee('Workshop · Signature')
        ->callAction('editSku', data: ['sku' => ' soap_100 '], arguments: ['sizeId' => $soapSize->id])
        ->assertHasNoActionErrors()
        ->assertSee('SOAP_100')
        ->set('search', 'soap_100')
        ->assertDontSee('Mint cleanser')
        ->set('search', '100g')
        ->assertSee('Turmeric soap')
        ->assertDontSee('Mint cleanser')
        ->set('search', 'bottle')
        ->assertSee('Mint cleanser')
        ->assertDontSee('Turmeric soap')
        ->set('search', '')
        ->set('brandFilter', (string) $brand->id)
        ->set('collectionFilter', (string) $otherCollection->id)
        ->assertSee('Mint cleanser')
        ->assertDontSee('Turmeric soap')
        ->callAction('editSku', data: ['sku' => 'soap_100'], arguments: ['sizeId' => $mintSize->id])
        ->assertHasFormErrors(['sku']);

    expect($soapSize->fresh()->sku)->toBe('SOAP_100')
        ->and($mintSize->fresh()->sku)->toBeNull();

    [$otherUser, $otherWorkspace] = inventoryModalWorkspace();
    $otherProduct = Recipe::factory()->for($otherWorkspace)->create();
    $foreignSize = ProductSize::factory()->for($otherProduct)->create();
    $save = app(SaveProductSizeSku::class);
    $save->handle($otherUser, $otherWorkspace, $foreignSize->id, 'soap_100');
    expect($foreignSize->fresh()->sku)->toBe('SOAP_100');

    $page->unmountAction()
        ->callAction('editSku', data: ['sku' => ''], arguments: ['sizeId' => $soapSize->id])
        ->assertHasNoActionErrors();
    expect($soapSize->fresh()->sku)->toBeNull();
});

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

it('uses the same darker default and lighter hover for shared and Filament primary buttons', function (): void {
    $tokens = file_get_contents(resource_path('css/shared/soapkraft.css'));
    $appStyles = file_get_contents(resource_path('css/app.css'));
    $styles = file_get_contents(resource_path('css/shared/filament-soapkraft.css'));

    expect($tokens)
        ->toContain(":root,\n    [data-user-shell]")
        ->toContain('--color-button-primary: var(--color-accent-hover);')
        ->toContain('--color-button-primary-hover: var(--color-accent);');

    expect($appStyles)
        ->toContain(".sk-btn-primary {\n        background: var(--color-button-primary);\n        color: var(--color-on-accent);")
        ->toContain(".sk-btn-primary:hover {\n        background: var(--color-button-primary-hover);");

    expect($styles)
        ->toContain('[data-user-shell] .fi-btn.fi-color-primary:not(.fi-outlined)')
        ->toContain('--color-500: var(--color-button-primary);')
        ->toContain('background: var(--color-button-primary) !important;')
        ->toContain('color: var(--color-on-accent) !important;')
        ->toContain('[data-user-shell] .fi-btn.fi-color-primary:not(.fi-outlined):hover')
        ->toContain('background: var(--color-button-primary-hover) !important;');
});

it('filters and orders stock register lots from the selected material state', function (): void {
    [$user, $workspace] = inventoryModalWorkspace();
    $ingredient = Ingredient::factory()->create(['display_name' => 'Selected oil']);
    $otherIngredient = Ingredient::factory()->create(['display_name' => 'Other oil']);

    StockLot::factory()->for($workspace)->for($ingredient)->released()->create([
        'internal_lot_code' => 'LOT-Z',
    ]);
    StockLot::factory()->for($workspace)->for($ingredient)->released()->create([
        'internal_lot_code' => 'LOT-A',
    ]);
    StockLot::factory()->for($workspace)->for($otherIngredient)->released()->create([
        'internal_lot_code' => 'LOT-B',
    ]);

    $this->actingAs($user);

    Livewire::test(InventoryIndex::class, ['mode' => 'stock'])
        ->set('lotMaterial', $ingredient->public_id)
        ->set('lotMaterialType', 'ingredient')
        ->set('lotScope', 'all')
        ->set('lotSort', 'code')
        ->assertViewHas('lots', fn (LengthAwarePaginator $lots): bool => $lots->getCollection()->pluck('lot.internal_lot_code')->all() === ['LOT-A', 'LOT-Z']);
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
        ->and($lot->internal_lot_code)->toMatch('/^SK-\d{6}-1$/')
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

it('accepts an optional ingredient internal lot number through the stock modal', function (): void {
    [$user, $workspace] = inventoryModalWorkspace();
    $listing = inventoryModalListing($workspace);
    $this->actingAs($user);

    Livewire::test(InventoryIndex::class, ['mode' => 'stock'])
        ->callAction('addStock', data: [
            'supplier_listing_id' => $listing->id,
            'quantity' => '2', 'unit' => 'kg', 'price_per_unit' => '10',
            'currency' => $workspace->default_currency, 'stocked_at' => today()->toDateString(),
            'internal_lot_code' => 'MY-INGREDIENT-42',
        ])->assertHasNoFormErrors();

    expect(StockLot::query()->where('workspace_id', $workspace->id)->sole()->internal_lot_code)->toBe('MY-INGREDIENT-42');
});

it('shows duplicate internal lot number errors beside the stock modal field', function (): void {
    [$user, $workspace] = inventoryModalWorkspace();
    $listing = inventoryModalListing($workspace);
    StockLot::factory()->for($workspace)->create(['internal_lot_code' => 'EXISTING-LOT']);
    $this->actingAs($user);

    Livewire::test(InventoryIndex::class, ['mode' => 'stock'])
        ->callAction('addStock', data: [
            'supplier_listing_id' => $listing->id,
            'quantity' => '2', 'unit' => 'kg', 'price_per_unit' => '10',
            'currency' => $workspace->default_currency, 'stocked_at' => today()->toDateString(),
            'internal_lot_code' => 'EXISTING-LOT',
        ])->assertHasFormErrors(['internal_lot_code'])
        ->assertMountedActionModalSee(__('lot_numbering.validation.duplicate'));

    expect(StockLot::query()->where('workspace_id', $workspace->id)->count())->toBe(1);
});
