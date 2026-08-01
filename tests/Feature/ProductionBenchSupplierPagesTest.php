<?php

use App\Actions\Purchasing\SaveSupplierListing;
use App\ListingPriceBasis;
use App\Livewire\ProductionBench\Purchasing\SupplierDetail;
use App\Livewire\ProductionBench\Purchasing\SupplierIndex;
use App\Livewire\ProductionBench\Purchasing\SupplierListingIndex;
use App\MassDisplaySystem;
use App\Models\Ingredient;
use App\Models\IngredientTranslation;
use App\Models\Supplier;
use App\Models\SupplierListing;
use App\Models\SupportedLocale;
use App\Models\User;
use App\Models\UserPackagingItem;
use App\Models\Workspace;
use App\Services\ProductionBenchAccess;
use App\Services\SupplierListingPricePresentation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('protects focused purchasing pages and renders each job separately', function (): void {
    $this->get(route('production-bench.purchasing.suppliers'))->assertRedirect(route('login'));

    [$owner, $workspace] = activeSupplierPagesWorkspace();

    $this->actingAs($owner)
        ->get(route('production-bench.purchasing.suppliers'))
        ->assertOk()
        ->assertSee('Suppliers')
        ->assertDontSee('Receive delivery');

    $this->actingAs($owner)
        ->get(route('production-bench.purchasing.listings'))
        ->assertOk()
        ->assertSee('Supplier listings')
        ->assertDontSee('Add supplier')
        ->assertDontSee('Receive delivery');

    $this->actingAs($owner)
        ->get(route('production-bench.purchasing.suppliers'))
        ->assertDontSee('Commercial pack')
        ->assertDontSee('UOM')
        ->assertDontSee('quarantine')
        ->assertDontSee('Coming later')
        ->assertDontSee('Quotation requests')
        ->assertDontSee('Purchase orders')
        ->assertDontSee('Receipts')
        ->assertDontSeeHtml('href="'.route('production-bench.purchasing.suppliers').'/quotation-requests"')
        ->assertDontSeeHtml('href="'.route('production-bench.purchasing.suppliers').'/purchase-orders"')
        ->assertDontSeeHtml('href="'.route('production-bench.purchasing.suppliers').'/receipts"');
});

it('keeps supplier mutations out of the index and detail pages', function (): void {
    [$owner, $workspace] = activeSupplierPagesWorkspace();
    $supplier = Supplier::factory()->for($workspace)->create([
        'code' => 'NORTHERN_01',
        'name' => 'Northern Oils',
        'address_line_1' => '12 Market Road',
        'city' => 'Leeds',
        'country_code' => 'GB',
        'contact_name' => 'Mira Smith',
    ]);
    $this->actingAs($owner);

    Livewire::test(SupplierIndex::class)
        ->assertSee('NORTHERN_01')
        ->assertSee('Northern Oils')
        ->assertSee('Add supplier')
        ->assertSeeHtml('href="'.route('production-bench.purchasing.suppliers.create').'"')
        ->assertDontSee('Save supplier')
        ->assertDontSeeHtml('wire:submit="saveSupplier"');

    Livewire::test(SupplierDetail::class, ['supplier' => $supplier->public_id])
        ->assertSee('NORTHERN_01')
        ->assertSee('Mira Smith')
        ->assertSee('12 Market Road')
        ->assertSee('Edit supplier')
        ->assertSeeHtml('href="'.route('production-bench.purchasing.suppliers.edit', $supplier).'"')
        ->assertSee('Add listing')
        ->assertSeeHtml('href="'.route('production-bench.purchasing.suppliers.listings.create', $supplier).'"')
        ->assertDontSee('Save supplier')
        ->assertDontSeeHtml('wire:submit="saveSupplier"')
        ->assertDontSeeHtml('wire:submit="saveListing"');

    Livewire::test(SupplierListingIndex::class)
        ->assertSee('Add listing')
        ->assertSeeHtml('href="'.route('production-bench.purchasing.listings.create').'"');
});

it('keeps supplier and listing browse pages operational and free of future feature copy', function (): void {
    [$owner, $workspace] = activeSupplierPagesWorkspace();
    $supplier = Supplier::factory()->for($workspace)->create();
    $this->actingAs($owner);

    $pages = [
        Livewire::test(SupplierIndex::class),
        Livewire::test(SupplierDetail::class, ['supplier' => $supplier->public_id]),
        Livewire::test(SupplierListingIndex::class),
    ];

    foreach ($pages as $page) {
        $page
            ->assertDontSee('Production Bench is not active.')
            ->assertDontSee('Resume Production Bench to make changes.')
            ->assertDontSee('Manage suppliers')
            ->assertDontSee('manage suppliers')
            ->assertDontSee('The supplier you buy from')
            ->assertDontSee('the supplier you buy from')
            ->assertDontSee('Coming later')
            ->assertDontSee('coming later')
            ->assertDontSee('Quotation requests')
            ->assertDontSee('Purchase orders')
            ->assertDontSee('Receipts');
    }

    $pages[0]
        ->assertDontSeeHtml('wire:submit="save"')
        ->assertDontSeeHtml('wire:submit="saveSupplier"');
    $pages[1]
        ->assertDontSeeHtml('wire:submit="save"')
        ->assertDontSeeHtml('wire:submit="saveSupplier"')
        ->assertDontSeeHtml('wire:submit="saveListing"');
});

it('shows mass and packaging supplier listings on the supplier detail page', function (): void {
    [$owner, $workspace] = activeSupplierPagesWorkspace();
    $this->actingAs($owner);
    $supplier = Supplier::factory()->for($workspace)->create();
    $ingredient = Ingredient::factory()->create(['display_name' => 'Coconut oil']);
    $packaging = UserPackagingItem::factory()->for($owner)->create(['name' => 'Amber bottle']);

    createSupplierPageListing($owner, $workspace, $supplier, $ingredient, [
        'purchase_format' => 'Drum',
        'net_quantity' => '200',
        'net_unit' => 'kg',
        'price_basis' => ListingPriceBasis::PerUnit,
        'price_amount' => '4.20',
        'price_unit' => 'kg',
    ]);
    createSupplierPageListing($owner, $workspace, $supplier, $packaging, [
        'purchase_format' => 'Carton',
        'net_quantity' => '500',
        'net_unit' => 'count',
        'price_basis' => ListingPriceBasis::TotalPurchaseFormat,
        'price_amount' => '90',
        'price_unit' => null,
    ]);

    Livewire::test(SupplierDetail::class, ['supplier' => $supplier->public_id])
        ->assertSee('Coconut oil')
        ->assertSee('200 kg')
        ->assertSee('Drum')
        ->assertSee('840.00')
        ->assertSee('Amber bottle')
        ->assertSee('500 count')
        ->assertSee('Carton')
        ->assertSee('90.00');

    expect(SupplierListing::query()->where('supplier_id', $supplier->id)->count())->toBe(2);
});

it('does not expose supplier listing creation controls on the detail page', function (): void {
    [$owner, $workspace] = activeSupplierPagesWorkspace();
    $this->actingAs($owner);
    $supplier = Supplier::factory()->for($workspace)->create();

    Livewire::test(SupplierDetail::class, ['supplier' => $supplier->public_id])
        ->assertSee('Add listing')
        ->assertDontSee('Material type')
        ->assertDontSee('Price preview')
        ->assertDontSee('Save supplier listing');
});

it('shows entered and derived prices for total and per-unit supplier listings', function (): void {
    [$owner, $workspace] = activeSupplierPagesWorkspace();
    $this->actingAs($owner);
    $supplier = Supplier::factory()->for($workspace)->create();
    $ingredient = Ingredient::factory()->create(['display_name' => 'Olive oil']);
    $packaging = UserPackagingItem::factory()->for($owner)->create(['name' => 'Amber bottle']);

    createSupplierPageListing($owner, $workspace, $supplier, $ingredient, [
        'purchase_format' => 'Drum',
        'net_quantity' => '200',
        'net_unit' => 'kg',
        'price_basis' => ListingPriceBasis::TotalPurchaseFormat,
        'price_amount' => '900',
        'price_unit' => null,
    ]);
    createSupplierPageListing($owner, $workspace, $supplier, $packaging, [
        'purchase_format' => 'Carton',
        'net_quantity' => '500',
        'net_unit' => 'count',
        'price_basis' => ListingPriceBasis::TotalPurchaseFormat,
        'price_amount' => '90',
        'price_unit' => null,
    ]);
    createSupplierPageListing($owner, $workspace, $supplier, $ingredient, [
        'purchase_format' => 'Pail',
        'net_quantity' => '200',
        'net_unit' => 'kg',
        'price_basis' => ListingPriceBasis::PerUnit,
        'price_amount' => '4.20',
        'price_unit' => 'kg',
    ]);

    Livewire::test(SupplierDetail::class, ['supplier' => $supplier->public_id])
        ->assertSee('Total purchase-format price')
        ->assertSee('900.00')
        ->assertSee('4.50 / kg')
        ->assertSee('90.00')
        ->assertSee('0.18 / item')
        ->assertSee('Price per unit of measure')
        ->assertSee('4.20 / kg')
        ->assertSee('840.00');

    Livewire::test(SupplierListingIndex::class)
        ->fillForm(['status' => 'all'], 'filtersForm')
        ->assertSee('Total purchase-format price')
        ->assertSee('4.50 / kg')
        ->assertSee('0.18 / item')
        ->assertSee('Price per unit of measure')
        ->assertSee('840.00');
});

it('does not expose the former live supplier listing preview', function (): void {
    [$owner, $workspace] = activeSupplierPagesWorkspace(MassDisplaySystem::UsCustomary);
    $this->actingAs($owner);
    $supplier = Supplier::factory()->for($workspace)->create(['default_currency' => 'EUR']);

    Livewire::test(SupplierDetail::class, ['supplier' => $supplier->public_id])
        ->assertDontSee('Price preview')
        ->assertDontSee('Price unit')
        ->assertDontSee('Pricing basis');
});

it('keeps supplier and listing records inside the current workspace', function (): void {
    [$owner, $workspace] = activeSupplierPagesWorkspace();
    $foreignWorkspace = Workspace::factory()->create();
    $foreignSupplier = Supplier::factory()->for($foreignWorkspace)->create();
    $supplier = Supplier::factory()->for($workspace)->create();
    $this->actingAs($owner);

    $this->get(route('production-bench.purchasing.supplier', $foreignSupplier))->assertNotFound();

    Livewire::test(SupplierDetail::class, ['supplier' => $supplier->public_id])
        ->assertSet('supplier.public_id', $supplier->public_id);
});

it('lets read-only workspaces browse without allowing supplier mutations', function (): void {
    [$owner, $workspace] = activeSupplierPagesWorkspace();
    $supplier = Supplier::factory()->for($workspace)->create(['name' => 'Visible supplier']);
    app(ProductionBenchAccess::class)->cancel($owner, $workspace);
    $this->actingAs($owner);

    Livewire::test(SupplierIndex::class)
        ->assertSee('Visible supplier')
        ->assertSee('Read-only. Resume to edit.')
        ->assertDontSee('Resume Production Bench to make changes.')
        ->assertDontSee('Add supplier');

    Livewire::test(SupplierDetail::class, ['supplier' => $supplier->public_id])
        ->assertSee('Visible supplier')
        ->assertDontSee('Edit supplier')
        ->assertDontSee('Add listing');

    Livewire::test(SupplierListingIndex::class)
        ->assertSee('Read-only. Resume to edit.')
        ->assertDontSee('Resume Production Bench to make changes.')
        ->assertDontSee('Add listing');
});

it('shows neutral inactive and read-only supplier states', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $supplier = Supplier::factory()->for($workspace)->create();
    $this->actingAs($owner);

    $this->get(route('production-bench.purchasing.suppliers'))
        ->assertOk()
        ->assertSee('Inactive')
        ->assertSee('Production Bench')
        ->assertDontSee('Production Bench is not active.')
        ->assertDontSee('manage suppliers');

    app(ProductionBenchAccess::class)->activate($owner, $workspace);
    app(ProductionBenchAccess::class)->cancel($owner, $workspace);

    Livewire::test(SupplierDetail::class, ['supplier' => $supplier->public_id])
        ->assertSee('Read-only. Resume to edit.')
        ->assertDontSee('Add listing');
});

it('searches and filters focused supplier records', function (): void {
    [$owner, $workspace] = activeSupplierPagesWorkspace();
    $this->actingAs($owner);
    $activeSupplier = Supplier::factory()->for($workspace)->create(['code' => 'OLVEA_01', 'name' => 'Active Oils', 'is_active' => true]);
    $inactiveSupplier = Supplier::factory()->for($workspace)->create(['name' => 'Inactive Oils', 'is_active' => false]);
    $ingredient = Ingredient::factory()->create(['display_name' => 'Olive oil']);
    SupplierListing::factory()->for($workspace)->for($activeSupplier)->for($ingredient)->create(['purchase_format' => 'Tin']);

    Livewire::test(SupplierIndex::class)
        ->fillForm(['search' => 'active', 'status' => 'active'], 'filtersForm')
        ->assertSee('Active Oils')
        ->assertDontSee('Inactive Oils');

    Livewire::test(SupplierIndex::class)
        ->fillForm(['search' => 'olvea_01'], 'filtersForm')
        ->assertSee('Active Oils')
        ->assertDontSee('Inactive Oils');

    Livewire::test(SupplierIndex::class)
        ->fillForm(['status' => 'all'], 'filtersForm')
        ->assertSee('Active Oils')
        ->assertSee('Inactive Oils');

    Livewire::test(SupplierListingIndex::class)
        ->fillForm(['search' => 'Tin'], 'filtersForm')
        ->assertSee('Olive oil')
        ->assertSee('Active Oils');
});

it('renders supplier index filters with the shared Filament field system', function (): void {
    [$owner] = activeSupplierPagesWorkspace();
    $this->actingAs($owner);

    Livewire::test(SupplierIndex::class)
        ->assertSeeHtml('fi-input-wrp')
        ->assertDontSeeHtml('wire:model.live.debounce.300ms="search"')
        ->fillForm(['search' => 'oil', 'status' => 'all', 'sort' => 'name'], 'filtersForm')
        ->assertSchemaStateSet(['search' => 'oil', 'status' => 'all', 'sort' => 'name'], 'filtersForm');

    Livewire::test(SupplierListingIndex::class)
        ->assertSeeHtml('fi-input-wrp')
        ->assertDontSeeHtml('wire:model.live.debounce.300ms="search"')
        ->fillForm(['search' => 'drum', 'material_type' => 'ingredient', 'status' => 'all'], 'filtersForm')
        ->assertSchemaStateSet(['search' => 'drum', 'material_type' => 'ingredient', 'status' => 'all'], 'filtersForm');
});

it('resets index pagination when Filament filters change', function (): void {
    [$owner] = activeSupplierPagesWorkspace();
    $this->actingAs($owner);

    Livewire::test(SupplierIndex::class)
        ->call('gotoPage', 2)
        ->assertSet('paginators.page', 2)
        ->fillForm(['search' => 'oil'], 'filtersForm')
        ->assertSet('paginators.page', 1);

    Livewire::test(SupplierListingIndex::class)
        ->call('gotoPage', 2)
        ->assertSet('paginators.page', 2)
        ->fillForm(['status' => 'all'], 'filtersForm')
        ->assertSet('paginators.page', 1);
});

it('bounds supplier filter search to the current workspace and resolves selected labels', function (): void {
    [$owner, $workspace] = activeSupplierPagesWorkspace();
    $foreignWorkspace = Workspace::factory()->create();
    Supplier::factory()->count(25)->for($workspace)->sequence(fn ($sequence) => [
        'code' => 'LOCAL_'.$sequence->index,
        'name' => 'Local supplier '.$sequence->index,
    ])->create();
    $selected = Supplier::factory()->for($workspace)->create(['code' => 'CHOSEN', 'name' => 'Chosen supplier']);
    $foreign = Supplier::factory()->for($foreignWorkspace)->create(['code' => 'FOREIGN', 'name' => 'Foreign supplier']);
    $this->actingAs($owner);

    $component = Livewire::test(SupplierListingIndex::class);
    $results = $component->instance()->supplierFilterSearchResults('supplier');

    expect($results)->toHaveCount(20)
        ->and($component->instance()->supplierFilterOptionLabel($selected->id))->toBe('CHOSEN · Chosen supplier')
        ->and($component->instance()->supplierFilterOptionLabel($foreign->id))->toBeNull();
});

it('filters supplier listings by supplier and material type and paginates supplier records', function (): void {
    [$owner, $workspace] = activeSupplierPagesWorkspace();
    $this->actingAs($owner);
    $supplier = Supplier::factory()->for($workspace)->create(['name' => 'Material supplier']);
    $otherSupplier = Supplier::factory()->for($workspace)->create(['name' => 'Other supplier']);
    $ingredient = Ingredient::factory()->create(['display_name' => 'Olive oil']);
    $packaging = UserPackagingItem::factory()->for($owner)->create(['name' => 'Bottle']);
    SupplierListing::factory()->for($workspace)->for($supplier)->for($ingredient)->create(['purchase_format' => 'Ingredient tin']);
    SupplierListing::factory()->for($workspace)->for($supplier)->create([
        'ingredient_id' => null,
        'user_packaging_item_id' => $packaging->id,
        'unit_kind' => 'count',
        'net_quantity' => '100',
        'net_unit' => 'count',
        'purchase_format' => 'Packaging carton',
    ]);
    SupplierListing::factory()->for($workspace)->for($otherSupplier)->for($ingredient)->create(['purchase_format' => 'Other tin']);
    Supplier::factory()->count(26)->for($workspace)->sequence(fn ($sequence) => ['name' => 'Paged supplier '.$sequence->index])->create();

    Livewire::test(SupplierListingIndex::class)
        ->fillForm(['supplier_id' => $supplier->id, 'material_type' => 'ingredient'], 'filtersForm')
        ->assertSee('Ingredient tin')
        ->assertDontSee('Packaging carton')
        ->assertDontSee('Other tin');

    Livewire::test(SupplierIndex::class)
        ->fillForm(['status' => 'all'], 'filtersForm')
        ->assertSee('Paged supplier 25')
        ->assertDontSee('Paged supplier 0')
        ->call('gotoPage', 2)
        ->assertSee('Paged supplier 0');
});

it('bounds manipulated supplier and listing page sizes', function (): void {
    [$owner, $workspace] = activeSupplierPagesWorkspace();
    $this->actingAs($owner);
    $supplier = Supplier::factory()->for($workspace)->create();
    $ingredient = Ingredient::factory()->create();

    Supplier::factory()->count(30)->for($workspace)->create();
    SupplierListing::factory()->count(30)->for($workspace)->for($supplier)->for($ingredient)->create();

    Livewire::test(SupplierIndex::class)
        ->fillForm(['status' => 'all'], 'filtersForm')
        ->set('perPage', 1000)
        ->assertSet('perPage', 25)
        ->assertViewHas('suppliers', fn ($suppliers): bool => $suppliers->count() === 25);

    Livewire::test(SupplierListingIndex::class)
        ->set('perPage', -1)
        ->assertSet('perPage', 25)
        ->assertViewHas('listingRows', fn ($listings): bool => $listings->count() === 25);
});

it('paginates supplier detail listings with bounded page sizes', function (): void {
    [$owner, $workspace] = activeSupplierPagesWorkspace();
    $this->actingAs($owner);
    $supplier = Supplier::factory()->for($workspace)->create();
    $listingIngredient = Ingredient::factory()->create();
    SupplierListing::factory()->count(30)->for($workspace)->for($supplier)->for($listingIngredient)
        ->sequence(fn ($sequence) => ['purchase_format' => 'Page listing '.$sequence->index])
        ->create();
    Ingredient::factory()->count(55)->create();

    Livewire::test(SupplierDetail::class, ['supplier' => $supplier->public_id])
        ->assertSee('Page listing 29')
        ->assertDontSee('Page listing 0')
        ->call('gotoPage', 2, 'supplier-listings')
        ->assertSee('Page listing 0')
        ->set('perPage', 50)
        ->assertSet('perPage', 50)
        ->set('perPage', -1)
        ->assertSet('perPage', 25);
});

it('eager loads translated supplier detail materials', function (): void {
    [$owner, $workspace] = activeSupplierPagesWorkspace();
    $this->actingAs($owner);
    app()->setLocale('fr');
    SupportedLocale::factory()->create(['code' => 'fr']);
    $supplier = Supplier::factory()->for($workspace)->create();
    $ingredient = Ingredient::factory()->create(['display_name' => 'Olive oil']);
    IngredientTranslation::factory()->create([
        'ingredient_id' => $ingredient->id,
        'locale' => 'fr',
        'display_name' => 'Huile d’olive',
    ]);
    $secondIngredient = Ingredient::factory()->create(['display_name' => 'Coconut oil']);
    IngredientTranslation::factory()->create([
        'ingredient_id' => $secondIngredient->id,
        'locale' => 'fr',
        'display_name' => 'Huile de coco',
    ]);
    $foreignWorkspace = Workspace::factory()->create();
    $foreignIngredient = Ingredient::factory()->create([
        'owner_type' => 'workspace',
        'owner_id' => $foreignWorkspace->id,
        'workspace_id' => $foreignWorkspace->id,
        'visibility' => 'private',
    ]);
    IngredientTranslation::factory()->create([
        'ingredient_id' => $foreignIngredient->id,
        'locale' => 'fr',
        'display_name' => 'Huile étrangère',
    ]);
    SupplierListing::factory()->for($workspace)->for($supplier)->for($ingredient)->create();
    SupplierListing::factory()->for($workspace)->for($supplier)->for($secondIngredient)->create();

    $wasPreventingLazyLoading = Model::preventsLazyLoading();
    Model::preventLazyLoading();

    try {
        Livewire::test(SupplierDetail::class, ['supplier' => $supplier->public_id])
            ->assertSee('Huile d’olive')
            ->assertSee('Huile de coco')
            ->assertDontSee('Huile étrangère');
    } finally {
        Model::preventLazyLoading($wasPreventingLazyLoading);
    }

    Livewire::test(SupplierListingIndex::class)
        ->fillForm(['search' => 'Huile'], 'filtersForm')
        ->assertSee('Huile d’olive')
        ->assertSee('Huile de coco')
        ->assertDontSee('Huile étrangère');
});

it('defaults supplier detail listings to active and identifies inactive listings in all states', function (): void {
    [$owner, $workspace] = activeSupplierPagesWorkspace();
    $this->actingAs($owner);
    $supplier = Supplier::factory()->for($workspace)->create();
    $ingredient = Ingredient::factory()->create();
    SupplierListing::factory()->for($workspace)->for($supplier)->for($ingredient)->create(['purchase_format' => 'Active drum', 'is_active' => true]);
    SupplierListing::factory()->for($workspace)->for($supplier)->for($ingredient)->create(['purchase_format' => 'Inactive drum', 'is_active' => false]);

    Livewire::test(SupplierDetail::class, ['supplier' => $supplier->public_id])
        ->assertSee('Active drum')
        ->assertDontSee('Inactive drum')
        ->set('listingStatus', 'all')
        ->assertSee('Inactive drum')
        ->assertSee('Inactive');
});

it('labels purchasing filters and marks the local supplier navigation as current', function (): void {
    [$owner, $workspace] = activeSupplierPagesWorkspace();
    $this->actingAs($owner);
    $supplier = Supplier::factory()->for($workspace)->create();

    $this->get(route('production-bench.purchasing.suppliers'))
        ->assertSee('Search')
        ->assertSee('Status')
        ->assertSee('Sort')
        ->assertDontSee('Search suppliers')
        ->assertDontSee('Supplier state')
        ->assertDontSee('Supplier sort')
        ->assertSeeHtml('aria-current="page"');

    $this->get(route('production-bench.purchasing.listings'))
        ->assertSee('Search')
        ->assertSee('Supplier')
        ->assertSee('Type')
        ->assertSee('Status')
        ->assertDontSee('Search supplier listings')
        ->assertDontSee('Filter by supplier')
        ->assertDontSee('Material type')
        ->assertDontSee('Listing state');

    $this->get(route('production-bench.purchasing.supplier', $supplier))
        ->assertSeeHtml('aria-current="page"');
});

/** @return array{0: User, 1: Workspace} */
function activeSupplierPagesWorkspace(MassDisplaySystem $massDisplaySystem = MassDisplaySystem::Metric): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create([
        'mass_display_system' => $massDisplaySystem,
    ]);
    app(ProductionBenchAccess::class)->activate($owner, $workspace);

    return [$owner, $workspace];
}

/** @param array<string, mixed> $attributes */
function createSupplierPageListing(
    User $owner,
    Workspace $workspace,
    Supplier $supplier,
    Ingredient|UserPackagingItem $subject,
    array $attributes,
): SupplierListing {
    return app(SaveSupplierListing::class)->handle(
        $owner,
        $workspace,
        $supplier,
        $subject,
        [
            'supplier_sku' => null,
            'supplier_name' => null,
            'container' => null,
            'minimum_packs' => 1,
            'notes' => null,
            'is_active' => true,
            ...$attributes,
        ],
    );
}

it('presents currency-bearing supplier listing prices in metric and US customary workspaces', function (): void {
    [$owner, $workspace] = activeSupplierPagesWorkspace();
    $supplier = Supplier::factory()->for($workspace)->create();
    $ingredient = Ingredient::factory()->create();
    $packaging = UserPackagingItem::factory()->for($owner)->create();
    $totalMassListing = createSupplierPageListing($owner, $workspace, $supplier, $ingredient, [
        'purchase_format' => 'Drum',
        'net_quantity' => '200',
        'net_unit' => 'kg',
        'price_basis' => ListingPriceBasis::TotalPurchaseFormat,
        'price_amount' => '900',
        'price_unit' => null,
    ]);

    $totalPackagingListing = createSupplierPageListing($owner, $workspace, $supplier, $packaging, [
        'purchase_format' => 'Carton',
        'net_quantity' => '500',
        'net_unit' => 'count',
        'price_basis' => ListingPriceBasis::TotalPurchaseFormat,
        'price_amount' => '90',
        'price_unit' => null,
    ]);

    $perMassListing = createSupplierPageListing($owner, $workspace, $supplier, $ingredient, [
        'purchase_format' => 'Pail',
        'net_quantity' => '200',
        'net_unit' => 'kg',
        'price_basis' => ListingPriceBasis::PerUnit,
        'price_amount' => '4.20',
        'price_unit' => 'kg',
    ]);
    $perPackagingListing = createSupplierPageListing($owner, $workspace, $supplier, $packaging, [
        'purchase_format' => 'Tray',
        'net_quantity' => '500',
        'net_unit' => 'count',
        'price_basis' => ListingPriceBasis::PerUnit,
        'price_amount' => '0.18',
        'price_unit' => 'count',
    ]);
    $decimalBoundaryListing = createSupplierPageListing($owner, $workspace, $supplier, $ingredient, [
        'purchase_format' => 'Large decimal drum',
        'net_quantity' => '1',
        'net_unit' => 'kg',
        'price_basis' => ListingPriceBasis::TotalPurchaseFormat,
        'price_amount' => '1.004999999',
        'price_unit' => null,
    ]);
    $largeDecimalBoundaryListing = createSupplierPageListing($owner, $workspace, $supplier, $ingredient, [
        'purchase_format' => 'Large amount drum',
        'net_quantity' => '1',
        'net_unit' => 'kg',
        'price_basis' => ListingPriceBasis::TotalPurchaseFormat,
        'price_amount' => '24757471.004999999',
        'price_unit' => null,
    ]);
    $centBoundaryListing = createSupplierPageListing($owner, $workspace, $supplier, $ingredient, [
        'purchase_format' => 'Cent boundary drum',
        'net_quantity' => '1',
        'net_unit' => 'kg',
        'price_basis' => ListingPriceBasis::TotalPurchaseFormat,
        'price_amount' => '1.005000000',
        'price_unit' => null,
    ]);
    $pricePresentation = app(SupplierListingPricePresentation::class);

    expect($pricePresentation->present($totalMassListing, $workspace))->toMatchArray([
        'entered_price' => 'EUR 900.00',
        'derived_price' => 'EUR 4.50 / kg',
        'total_price' => 'EUR 900.00 total',
    ])->and($pricePresentation->present($perMassListing, $workspace))->toMatchArray([
        'entered_price' => 'EUR 4.20 / kg',
        'derived_price' => 'EUR 840.00 total',
        'total_price' => 'EUR 840.00 total',
    ])->and($pricePresentation->present($perPackagingListing, $workspace))->toMatchArray([
        'entered_price' => 'EUR 0.18 / item',
        'derived_price' => 'EUR 90.00 total',
        'total_price' => 'EUR 90.00 total',
    ])->and($pricePresentation->present($totalPackagingListing, $workspace))->toMatchArray([
        'entered_price' => 'EUR 90.00',
        'derived_price' => 'EUR 0.18 / item',
        'total_price' => 'EUR 90.00 total',
    ])->and($pricePresentation->present($decimalBoundaryListing, $workspace))->toMatchArray([
        'entered_price' => 'EUR 1.00',
        'derived_price' => 'EUR 1.00 / kg',
        'total_price' => 'EUR 1.00 total',
    ])->and($pricePresentation->present($largeDecimalBoundaryListing, $workspace))->toMatchArray([
        'entered_price' => 'EUR 24757471.00',
        'derived_price' => 'EUR 24757471.00 / kg',
        'total_price' => 'EUR 24757471.00 total',
    ])->and($pricePresentation->present($centBoundaryListing, $workspace))->toMatchArray([
        'entered_price' => 'EUR 1.01',
        'derived_price' => 'EUR 1.01 / kg',
        'total_price' => 'EUR 1.01 total',
    ]);

    [, $usWorkspace] = activeSupplierPagesWorkspace(MassDisplaySystem::UsCustomary);

    expect($pricePresentation->present($totalMassListing, $usWorkspace))->toMatchArray([
        'entered_price' => 'EUR 900.00',
        'derived_price' => 'EUR 2.04 / lb',
        'total_price' => 'EUR 900.00 total',
    ]);
});
