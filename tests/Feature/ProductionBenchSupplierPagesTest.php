<?php

use App\Actions\Purchasing\SaveSupplierListing;
use App\ListingPriceBasis;
use App\Livewire\ProductionBench\Purchasing\SupplierDetail;
use App\Livewire\ProductionBench\Purchasing\SupplierIndex;
use App\Livewire\ProductionBench\Purchasing\SupplierListingIndex;
use App\MassDisplaySystem;
use App\Models\Ingredient;
use App\Models\Supplier;
use App\Models\SupplierListing;
use App\Models\User;
use App\Models\UserPackagingItem;
use App\Models\Workspace;
use App\Services\ProductionBenchAccess;
use App\Services\SupplierListingPricePresentation;
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
        ->assertSee('Coming later')
        ->assertSeeHtml('aria-disabled="true"')
        ->assertDontSeeHtml('href="'.route('production-bench.purchasing.suppliers').'/quotation-requests"')
        ->assertDontSeeHtml('href="'.route('production-bench.purchasing.suppliers').'/purchase-orders"')
        ->assertDontSeeHtml('href="'.route('production-bench.purchasing.suppliers').'/receipts"');
});

it('creates and edits a supplier with structured details', function (): void {
    [$owner, $workspace] = activeSupplierPagesWorkspace();
    $this->actingAs($owner);

    Livewire::test(SupplierIndex::class)
        ->set([
            'name' => 'Northern Oils',
            'addressLine1' => '12 Market Road',
            'city' => 'Leeds',
            'countryCode' => 'gb',
            'website' => 'https://northern-oils.example',
            'contactName' => 'Mira Smith',
            'email' => 'mira@northern-oils.example',
            'phone' => '+44 113 555 0100',
            'defaultCurrency' => 'GBP',
            'notes' => 'Call before dispatch.',
        ])
        ->call('saveSupplier')
        ->assertHasNoErrors()
        ->assertSee('Northern Oils');

    $supplier = Supplier::query()->where('workspace_id', $workspace->id)->firstOrFail();

    expect($supplier->country_code)->toBe('GB')
        ->and($supplier->contact_name)->toBe('Mira Smith');

    Livewire::test(SupplierDetail::class, ['supplier' => $supplier->public_id])
        ->set('city', 'York')
        ->call('saveSupplier')
        ->assertHasNoErrors()
        ->assertSee('Supplier details saved');

    expect($supplier->fresh()?->city)->toBe('York');
});

it('creates mass and packaging supplier listings from the supplier detail page', function (): void {
    [$owner, $workspace] = activeSupplierPagesWorkspace();
    $this->actingAs($owner);
    $supplier = Supplier::factory()->for($workspace)->create();
    $ingredient = Ingredient::factory()->create(['display_name' => 'Coconut oil']);
    $packaging = UserPackagingItem::factory()->for($owner)->create(['name' => 'Amber bottle']);

    Livewire::test(SupplierDetail::class, ['supplier' => $supplier->public_id])
        ->set([
            'listingSubjectType' => 'ingredient',
            'listingSubjectId' => $ingredient->id,
            'purchaseFormat' => 'Drum',
            'netQuantity' => '200',
            'netUnit' => 'kg',
            'priceBasis' => ListingPriceBasis::PerUnit->value,
            'priceAmount' => '4.20',
            'priceUnit' => 'kg',
        ])
        ->call('saveListing')
        ->assertHasNoErrors()
        ->assertSee('200 kg')
        ->assertSee('Drum')
        ->assertSee('840.00');

    Livewire::test(SupplierDetail::class, ['supplier' => $supplier->public_id])
        ->set([
            'listingSubjectType' => 'ingredient',
            'listingSubjectId' => $ingredient->id,
            'purchaseFormat' => 'Pail',
            'netQuantity' => '25',
            'netUnit' => 'kg',
            'priceBasis' => ListingPriceBasis::TotalPurchaseFormat->value,
            'priceAmount' => '125',
            'priceUnit' => '',
        ])
        ->call('saveListing')
        ->assertHasNoErrors()
        ->assertSee('Pail');

    Livewire::test(SupplierDetail::class, ['supplier' => $supplier->public_id])
        ->set([
            'listingSubjectType' => 'packaging',
            'listingSubjectId' => $packaging->id,
            'purchaseFormat' => 'Carton',
            'netQuantity' => '500',
            'netUnit' => 'count',
            'priceBasis' => ListingPriceBasis::TotalPurchaseFormat->value,
            'priceAmount' => '90',
            'priceUnit' => '',
        ])
        ->call('saveListing')
        ->assertHasNoErrors()
        ->assertSee('500 count')
        ->assertSee('90.00');

    expect(SupplierListing::query()->where('supplier_id', $supplier->id)->count())->toBe(3);
});

it('clears stale price units for total purchase-format pricing and restores valid defaults', function (): void {
    [$owner, $workspace] = activeSupplierPagesWorkspace();
    $this->actingAs($owner);
    $supplier = Supplier::factory()->for($workspace)->create();
    $ingredient = Ingredient::factory()->create();
    $packaging = UserPackagingItem::factory()->for($owner)->create();

    Livewire::test(SupplierDetail::class, ['supplier' => $supplier->public_id])
        ->set([
            'listingSubjectType' => 'ingredient',
            'listingSubjectId' => $ingredient->id,
            'purchaseFormat' => 'Total drum',
            'netQuantity' => '200',
            'netUnit' => 'kg',
            'priceAmount' => '900',
        ])
        ->set('priceBasis', ListingPriceBasis::TotalPurchaseFormat->value)
        ->assertSet('priceUnit', '')
        ->call('saveListing')
        ->assertHasNoErrors()
        ->set('priceBasis', ListingPriceBasis::PerUnit->value)
        ->assertSet('priceUnit', 'kg');

    Livewire::test(SupplierDetail::class, ['supplier' => $supplier->public_id])
        ->set([
            'listingSubjectType' => 'packaging',
            'listingSubjectId' => $packaging->id,
            'purchaseFormat' => 'Total carton',
            'netQuantity' => '500',
            'netUnit' => 'count',
            'priceAmount' => '90',
        ])
        ->set('priceBasis', ListingPriceBasis::TotalPurchaseFormat->value)
        ->assertSet('priceUnit', '')
        ->call('saveListing')
        ->assertHasNoErrors();

    expect(SupplierListing::query()->where('supplier_id', $supplier->id)->pluck('price_unit')->all())->toBe([null, null]);

    [$usOwner, $usWorkspace] = activeSupplierPagesWorkspace(MassDisplaySystem::UsCustomary);
    $usSupplier = Supplier::factory()->for($usWorkspace)->create();
    $this->actingAs($usOwner);

    Livewire::test(SupplierDetail::class, ['supplier' => $usSupplier->public_id])
        ->set('priceBasis', ListingPriceBasis::TotalPurchaseFormat->value)
        ->assertSet('priceUnit', '')
        ->set('priceBasis', ListingPriceBasis::PerUnit->value)
        ->assertSet('priceUnit', 'lb');
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
        ->set('status', 'all')
        ->assertSee('Total purchase-format price')
        ->assertSee('4.50 / kg')
        ->assertSee('0.18 / item')
        ->assertSee('Price per unit of measure')
        ->assertSee('840.00');
});

it('keeps supplier and listing records inside the current workspace', function (): void {
    [$owner, $workspace] = activeSupplierPagesWorkspace();
    $foreignWorkspace = Workspace::factory()->create();
    $foreignSupplier = Supplier::factory()->for($foreignWorkspace)->create();
    $foreignIngredient = Ingredient::factory()->create([
        'owner_type' => 'workspace',
        'owner_id' => $foreignWorkspace->id,
        'workspace_id' => $foreignWorkspace->id,
        'visibility' => 'private',
    ]);
    $supplier = Supplier::factory()->for($workspace)->create();
    $this->actingAs($owner);

    $this->get(route('production-bench.purchasing.supplier', $foreignSupplier))->assertNotFound();

    Livewire::test(SupplierDetail::class, ['supplier' => $supplier->public_id])
        ->set([
            'listingSubjectType' => 'ingredient',
            'listingSubjectId' => $foreignIngredient->id,
            'purchaseFormat' => 'Drum',
            'netQuantity' => '20',
            'netUnit' => 'kg',
            'priceBasis' => ListingPriceBasis::TotalPurchaseFormat->value,
            'priceAmount' => '50',
        ])
        ->call('saveListing')
        ->assertHasErrors('listingSubjectId');
});

it('lets read-only workspaces browse without allowing supplier mutations', function (): void {
    [$owner, $workspace] = activeSupplierPagesWorkspace();
    $supplier = Supplier::factory()->for($workspace)->create(['name' => 'Visible supplier']);
    app(ProductionBenchAccess::class)->cancel($owner, $workspace);
    $this->actingAs($owner);

    Livewire::test(SupplierIndex::class)
        ->assertSee('Visible supplier')
        ->set('name', 'Blocked supplier')
        ->call('saveSupplier')
        ->assertHasErrors('production_bench');

    expect(Supplier::query()->where('name', 'Blocked supplier')->exists())->toBeFalse();
});

it('shows activation guidance for inactive benches and rejects read-only listing mutations', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $supplier = Supplier::factory()->for($workspace)->create();
    $ingredient = Ingredient::factory()->create();
    $this->actingAs($owner);

    $this->get(route('production-bench.purchasing.suppliers'))
        ->assertOk()
        ->assertSee('Activate the bench');

    app(ProductionBenchAccess::class)->activate($owner, $workspace);
    app(ProductionBenchAccess::class)->cancel($owner, $workspace);

    Livewire::test(SupplierDetail::class, ['supplier' => $supplier->public_id])
        ->set([
            'listingSubjectId' => $ingredient->id,
            'purchaseFormat' => 'Blocked drum',
            'netQuantity' => '20',
            'netUnit' => 'kg',
            'priceBasis' => ListingPriceBasis::PerUnit->value,
            'priceAmount' => '4',
            'priceUnit' => 'kg',
        ])
        ->call('saveListing')
        ->assertHasErrors('production_bench');

    expect(SupplierListing::query()->where('purchase_format', 'Blocked drum')->exists())->toBeFalse();
});

it('searches and filters focused supplier records', function (): void {
    [$owner, $workspace] = activeSupplierPagesWorkspace();
    $this->actingAs($owner);
    $activeSupplier = Supplier::factory()->for($workspace)->create(['name' => 'Active Oils', 'is_active' => true]);
    $inactiveSupplier = Supplier::factory()->for($workspace)->create(['name' => 'Inactive Oils', 'is_active' => false]);
    $ingredient = Ingredient::factory()->create(['display_name' => 'Olive oil']);
    SupplierListing::factory()->for($workspace)->for($activeSupplier)->for($ingredient)->create(['purchase_format' => 'Tin']);

    Livewire::test(SupplierIndex::class)
        ->set('search', 'active')
        ->set('status', 'active')
        ->assertSee('Active Oils')
        ->assertDontSee('Inactive Oils');

    Livewire::test(SupplierIndex::class)
        ->set('status', 'all')
        ->assertSee('Active Oils')
        ->assertSee('Inactive Oils');

    Livewire::test(SupplierListingIndex::class)
        ->set('search', 'Tin')
        ->assertSee('Olive oil')
        ->assertSee('Active Oils');
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
        ->set('supplierId', $supplier->id)
        ->set('materialType', 'ingredient')
        ->assertSee('Ingredient tin')
        ->assertDontSee('Packaging carton')
        ->assertDontSee('Other tin');

    Livewire::test(SupplierIndex::class)
        ->set('status', 'all')
        ->assertSee('Paged supplier 25')
        ->assertDontSee('Paged supplier 0')
        ->call('gotoPage', 2)
        ->assertSee('Paged supplier 0');
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

it('presents a listing price directly', function (): void {
    [$owner, $workspace] = activeSupplierPagesWorkspace();
    $supplier = Supplier::factory()->for($workspace)->create();
    $ingredient = Ingredient::factory()->create();
    $packaging = UserPackagingItem::factory()->for($owner)->create();
    $listing = createSupplierPageListing($owner, $workspace, $supplier, $ingredient, [
        'purchase_format' => 'Drum',
        'net_quantity' => '200',
        'net_unit' => 'kg',
        'price_basis' => ListingPriceBasis::TotalPurchaseFormat,
        'price_amount' => '900',
        'price_unit' => null,
    ]);

    $packagingListing = createSupplierPageListing($owner, $workspace, $supplier, $packaging, [
        'purchase_format' => 'Carton',
        'net_quantity' => '500',
        'net_unit' => 'count',
        'price_basis' => ListingPriceBasis::TotalPurchaseFormat,
        'price_amount' => '90',
        'price_unit' => null,
    ]);

    expect(app(SupplierListingPricePresentation::class)->present($listing, $workspace))->toBeArray()
        ->and(app(SupplierListingPricePresentation::class)->present($packagingListing, $workspace))->toBeArray();
});
