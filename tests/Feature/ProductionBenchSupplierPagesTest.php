<?php

use App\ListingPriceBasis;
use App\Livewire\ProductionBench\Purchasing\SupplierDetail;
use App\Livewire\ProductionBench\Purchasing\SupplierIndex;
use App\Livewire\ProductionBench\Purchasing\SupplierListingIndex;
use App\Models\Ingredient;
use App\Models\Supplier;
use App\Models\SupplierListing;
use App\Models\User;
use App\Models\UserPackagingItem;
use App\Models\Workspace;
use App\Services\ProductionBenchAccess;
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

    Livewire::test(SupplierListingIndex::class)
        ->set('search', 'Tin')
        ->assertSee('Olive oil')
        ->assertSee('Active Oils');
});

/** @return array{0: User, 1: Workspace} */
function activeSupplierPagesWorkspace(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($owner, $workspace);

    return [$owner, $workspace];
}
