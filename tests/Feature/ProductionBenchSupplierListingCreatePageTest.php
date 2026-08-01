<?php

use App\ListingPriceBasis;
use App\Livewire\ProductionBench\Purchasing\SupplierListingCreate;
use App\MassDisplaySystem;
use App\Models\Ingredient;
use App\Models\Supplier;
use App\Models\SupplierListing;
use App\Models\User;
use App\Models\UserPackagingItem;
use App\Models\Workspace;
use App\OwnerType;
use App\Services\ProductionBenchAccess;
use App\Visibility;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('protects both supplier listing creation routes', function (): void {
    $supplier = Supplier::factory()->create();

    $this->get('/dashboard/production-bench/purchasing/listings/new')
        ->assertRedirect(route('login'));
    $this->get('/dashboard/production-bench/purchasing/suppliers/'.$supplier->public_id.'/listings/new')
        ->assertRedirect(route('login'));
});

it('shows a focused global listing form with searchable catalog selectors', function (): void {
    [$owner, $workspace] = listingCreateWorkspace();
    $supplier = Supplier::factory()->for($workspace)->create(['code' => 'OLVEA', 'name' => 'Olvea']);
    Ingredient::factory()->create(['display_name' => 'Olive oil']);
    UserPackagingItem::factory()->for($owner)->create(['name' => 'Amber bottle']);

    $this->actingAs($owner)
        ->get('/dashboard/production-bench/purchasing/listings/new')
        ->assertOk()
        ->assertSee('New supplier listing')
        ->assertSee('Supplier')
        ->assertSee('OLVEA · Olvea')
        ->assertSee('Olive oil')
        ->assertSee('Purchase format')
        ->assertSee('Pricing')
        ->assertSee('Save supplier listing')
        ->assertSeeHtml('role="combobox"')
        ->assertSeeHtml('href="'.route('production-bench.purchasing.listings').'"');

    Livewire::test(SupplierListingCreate::class)
        ->set('materialType', 'packaging')
        ->assertSee('Amber bottle');

    expect(SupplierListing::query()->count())->toBe(0);
});

it('requires a supplier on the global listing form', function (): void {
    [$owner] = listingCreateWorkspace();
    $ingredient = Ingredient::factory()->create();
    $this->actingAs($owner);

    Livewire::test(SupplierListingCreate::class)
        ->set('materialType', 'ingredient')
        ->set('ingredientId', $ingredient->id)
        ->set('purchaseFormat', '200 kg drum')
        ->set('netQuantity', '200')
        ->set('netUnit', 'kg')
        ->set('priceBasis', ListingPriceBasis::PerUnit->value)
        ->set('priceAmount', '4.20')
        ->set('priceUnit', 'kg')
        ->call('save')
        ->assertHasErrors(['supplierId' => 'required']);

    expect(SupplierListing::query()->count())->toBe(0);
});

it('creates an exact-price mass listing globally and returns to the listings index', function (): void {
    [$owner, $workspace] = listingCreateWorkspace();
    $supplier = Supplier::factory()->for($workspace)->create(['default_currency' => 'EUR']);
    $ingredient = Ingredient::factory()->create(['display_name' => 'Olive oil']);
    $this->actingAs($owner);

    Livewire::test(SupplierListingCreate::class)
        ->set('supplierId', $supplier->id)
        ->set('materialType', 'ingredient')
        ->set('ingredientId', $ingredient->id)
        ->set('supplierSku', 'OO-200')
        ->set('supplierName', 'Extra virgin olive oil')
        ->set('purchaseFormat', '200 kg drum')
        ->set('netQuantity', '200')
        ->set('netUnit', 'kg')
        ->set('priceBasis', ListingPriceBasis::PerUnit->value)
        ->set('priceAmount', '4.20')
        ->set('priceUnit', 'kg')
        ->set('currency', 'EUR')
        ->set('minimumPacks', 2)
        ->set('notes', 'Food grade')
        ->assertSee('EUR 840.00 total')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('production-bench.purchasing.listings'));

    $listing = SupplierListing::query()->firstOrFail();

    expect($listing)->toMatchArray([
        'workspace_id' => $workspace->id,
        'supplier_id' => $supplier->id,
        'ingredient_id' => $ingredient->id,
        'supplier_sku' => 'OO-200',
        'supplier_name' => 'Extra virgin olive oil',
        'purchase_format' => '200 kg drum',
        'net_quantity' => '200.000000000',
        'net_unit' => 'kg',
        'price_amount' => '4.200000000',
        'price_unit' => 'kg',
        'total_price' => '840.000000000',
        'currency' => 'EUR',
        'minimum_packs' => 2,
        'notes' => 'Food grade',
        'is_active' => true,
    ]);
});

it('locks supplier context and returns scoped packaging listings to supplier detail', function (): void {
    [$owner, $workspace] = listingCreateWorkspace();
    $supplier = Supplier::factory()->for($workspace)->create(['code' => 'BOTTLES', 'name' => 'Bottle House']);
    $packaging = UserPackagingItem::factory()->for($owner)->create(['name' => 'Amber bottle']);
    $this->actingAs($owner);

    $this->get('/dashboard/production-bench/purchasing/suppliers/'.$supplier->public_id.'/listings/new')
        ->assertOk()
        ->assertSee('BOTTLES')
        ->assertSee('Bottle House')
        ->assertSee('Supplier selected')
        ->assertDontSeeHtml('id="supplier-listing-supplier-search"')
        ->assertSeeHtml('href="'.route('production-bench.purchasing.supplier', $supplier).'"');

    Livewire::test(SupplierListingCreate::class, ['supplier' => $supplier->public_id])
        ->assertSet('supplierId', $supplier->id)
        ->set('supplierId', Supplier::factory()->for($workspace)->create()->id)
        ->set('materialType', 'packaging')
        ->set('packagingItemId', $packaging->id)
        ->set('purchaseFormat', 'Carton of 500')
        ->set('netQuantity', '500')
        ->set('priceBasis', ListingPriceBasis::TotalPurchaseFormat->value)
        ->set('priceAmount', '90')
        ->set('currency', 'EUR')
        ->assertSee('EUR 0.18 / item')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('production-bench.purchasing.supplier', $supplier));

    $listing = SupplierListing::query()->firstOrFail();

    expect($listing->supplier_id)->toBe($supplier->id)
        ->and($listing->user_packaging_item_id)->toBe($packaging->id)
        ->and($listing->ingredient_id)->toBeNull()
        ->and($listing->net_unit)->toBe('count')
        ->and($listing->total_price)->toBe('90.000000000');
});

it('uses the workspace mass basis for listing defaults and previews total prices', function (): void {
    [$owner, $workspace] = listingCreateWorkspace(MassDisplaySystem::UsCustomary);
    $supplier = Supplier::factory()->for($workspace)->create(['default_currency' => 'USD']);
    $ingredient = Ingredient::factory()->create();
    $this->actingAs($owner);

    Livewire::test(SupplierListingCreate::class)
        ->assertSet('netUnit', 'lb')
        ->assertSet('priceUnit', 'lb')
        ->set('supplierId', $supplier->id)
        ->set('materialType', 'ingredient')
        ->set('ingredientId', $ingredient->id)
        ->set('purchaseFormat', '50 lb pail')
        ->set('netQuantity', '50')
        ->set('netUnit', 'lb')
        ->set('priceBasis', ListingPriceBasis::TotalPurchaseFormat->value)
        ->set('priceAmount', '125')
        ->set('priceUnit', '')
        ->set('currency', 'USD')
        ->assertSee('USD 2.50 / lb');
});

it('rejects a currency outside the maintained catalog', function (): void {
    [$owner, $workspace] = listingCreateWorkspace();
    $supplier = Supplier::factory()->for($workspace)->create();
    $ingredient = Ingredient::factory()->create();
    $this->actingAs($owner);

    Livewire::test(SupplierListingCreate::class)
        ->set('supplierId', $supplier->id)
        ->set('ingredientId', $ingredient->id)
        ->set('purchaseFormat', 'Drum')
        ->set('netQuantity', '1')
        ->set('netUnit', 'kg')
        ->set('priceBasis', ListingPriceBasis::PerUnit->value)
        ->set('priceAmount', '1')
        ->set('priceUnit', 'kg')
        ->set('currency', 'ZZZ')
        ->call('save')
        ->assertHasErrors(['currency']);

    expect(SupplierListing::query()->count())->toBe(0);
});

it('does not preselect a historical supplier currency for a new listing', function (): void {
    [$owner, $workspace] = listingCreateWorkspace();
    $supplier = Supplier::factory()->for($workspace)->create(['default_currency' => 'HRK']);
    $this->actingAs($owner);

    Livewire::test(SupplierListingCreate::class)
        ->assertSet('currency', 'EUR')
        ->set('supplierId', $supplier->id)
        ->assertSet('currency', 'EUR');

    Livewire::test(SupplierListingCreate::class, ['supplier' => $supplier->public_id])
        ->assertSet('currency', 'EUR');

    $historicalOwner = User::factory()->create();
    $historicalWorkspace = Workspace::factory()->for($historicalOwner, 'owner')->create(['default_currency' => 'HRK']);
    $historicalSupplier = Supplier::factory()->for($historicalWorkspace)->create(['default_currency' => 'HRK']);
    app(ProductionBenchAccess::class)->activate($historicalOwner, $historicalWorkspace);
    $this->actingAs($historicalOwner);

    Livewire::test(SupplierListingCreate::class, ['supplier' => $historicalSupplier->public_id])
        ->assertSet('currency', '');
});

it('bounds server-side catalog searches and retains selected rows', function (): void {
    [$owner, $workspace] = listingCreateWorkspace();
    $suppliers = Supplier::factory()->count(30)->for($workspace)->sequence(
        fn ($sequence): array => [
            'code' => sprintf('SUP-%02d', $sequence->index + 1),
            'name' => sprintf('Supplier %02d', $sequence->index + 1),
        ],
    )->create();
    $ingredients = Ingredient::factory()->count(30)->sequence(
        fn ($sequence): array => ['display_name' => sprintf('Ingredient %02d', $sequence->index + 1)],
    )->create();
    $packagingItems = UserPackagingItem::factory()->count(30)->for($owner)->sequence(
        fn ($sequence): array => ['name' => sprintf('Packaging %02d', $sequence->index + 1)],
    )->create();
    $selectedSupplier = $suppliers->last();
    $this->actingAs($owner);

    $component = Livewire::test(SupplierListingCreate::class);

    expect($component->get('supplierOptions'))->toHaveCount(20)
        ->and($component->get('ingredientOptions'))->toHaveCount(20)
        ->and($component->get('packagingOptions'))->toBe([]);

    $component
        ->set('supplierId', $selectedSupplier->id)
        ->call('searchSupplierOptions', 'no matching supplier');

    expect($component->get('supplierOptions'))
        ->toHaveCount(1)
        ->and(collect($component->get('supplierOptions'))->pluck('id')->all())
        ->toContain($selectedSupplier->id);

    $catalogQueries = [];
    DB::listen(function (QueryExecuted $query) use (&$catalogQueries): void {
        $catalogQueries[] = $query->sql;
    });

    $component->call('searchIngredientOptions', 'Ingredient');

    expect(collect($catalogQueries)->contains(
        fn (string $sql): bool => Str::contains($sql, 'from "ingredients"')
            && Str::contains($sql, 'limit 20'),
    ))->toBeTrue()
        ->and(collect($catalogQueries)->contains(
            fn (string $sql): bool => Str::contains($sql, 'user_packaging_items'),
        ))->toBeFalse();

    $component
        ->set('ingredientId', $ingredients->last()->id)
        ->call('searchIngredientOptions', 'no matching ingredient');

    expect(collect($component->get('ingredientOptions'))->pluck('id')->all())
        ->toBe([$ingredients->last()->id]);

    $component->set('materialType', 'packaging');

    expect($component->get('ingredientOptions'))->toBe([])
        ->and($component->get('packagingOptions'))->toHaveCount(20);

    $component
        ->set('packagingItemId', $packagingItems->last()->id)
        ->call('searchPackagingOptions', 'no matching packaging item');

    expect(collect($component->get('packagingOptions'))->pluck('id')->all())
        ->toBe([$packagingItems->last()->id]);
});

it('does not requery supplier or material catalogs for price preview updates', function (): void {
    [$owner, $workspace] = listingCreateWorkspace();
    Supplier::factory()->count(3)->for($workspace)->create();
    Ingredient::factory()->count(3)->create();
    UserPackagingItem::factory()->count(3)->for($owner)->create();
    $this->actingAs($owner);

    $component = Livewire::test(SupplierListingCreate::class);
    $selectorQueries = [];

    DB::listen(function (QueryExecuted $query) use (&$selectorQueries): void {
        if (Str::contains($query->sql, ['suppliers', 'ingredients', 'ingredient_translations', 'user_packaging_items'])) {
            $selectorQueries[] = $query->sql;
        }
    });

    $component
        ->set('netQuantity', '2')
        ->set('priceAmount', '4.25');

    expect($selectorQueries)->toBe([]);
});

it('keeps supplier and material selection inside the current workspace', function (): void {
    [$owner, $workspace] = listingCreateWorkspace();
    $supplier = Supplier::factory()->for($workspace)->create();
    $foreignWorkspace = Workspace::factory()->create();
    $foreignSupplier = Supplier::factory()->for($foreignWorkspace)->create(['name' => 'Foreign Supplier']);
    $foreignIngredient = Ingredient::factory()->create([
        'display_name' => 'Foreign oil',
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $foreignWorkspace->id,
        'workspace_id' => $foreignWorkspace->id,
        'visibility' => Visibility::Private,
    ]);
    $foreignPackaging = UserPackagingItem::factory()->create(['name' => 'Foreign bottle']);
    $this->actingAs($owner);

    $this->get('/dashboard/production-bench/purchasing/suppliers/'.$foreignSupplier->public_id.'/listings/new')
        ->assertNotFound();

    Livewire::test(SupplierListingCreate::class)
        ->assertDontSee('Foreign Supplier')
        ->assertDontSee('Foreign oil')
        ->assertDontSee('Foreign bottle')
        ->set('supplierId', $foreignSupplier->id)
        ->set('materialType', 'ingredient')
        ->set('ingredientId', $foreignIngredient->id)
        ->set('purchaseFormat', 'Drum')
        ->set('netQuantity', '1')
        ->set('netUnit', 'kg')
        ->set('priceBasis', ListingPriceBasis::PerUnit->value)
        ->set('priceAmount', '1')
        ->set('priceUnit', 'kg')
        ->call('save')
        ->assertHasErrors('supplierId');

    Livewire::test(SupplierListingCreate::class)
        ->set('supplierId', $supplier->id)
        ->set('materialType', 'packaging')
        ->set('packagingItemId', $foreignPackaging->id)
        ->set('purchaseFormat', 'Carton')
        ->set('netQuantity', '1')
        ->set('priceBasis', ListingPriceBasis::PerUnit->value)
        ->set('priceAmount', '1')
        ->call('save')
        ->assertHasErrors('packagingItemId');

    expect(SupplierListing::query()->count())->toBe(0);
});

it('rejects inactive and read-only listing pages and blocks a later save', function (): void {
    $inactiveOwner = User::factory()->create();
    $inactiveWorkspace = Workspace::factory()->for($inactiveOwner, 'owner')->create();
    $inactiveSupplier = Supplier::factory()->for($inactiveWorkspace)->create();

    $this->actingAs($inactiveOwner)
        ->get('/dashboard/production-bench/purchasing/listings/new')
        ->assertForbidden();
    $this->get('/dashboard/production-bench/purchasing/suppliers/'.$inactiveSupplier->public_id.'/listings/new')
        ->assertForbidden();

    [$owner, $workspace] = listingCreateWorkspace();
    $supplier = Supplier::factory()->for($workspace)->create();
    $ingredient = Ingredient::factory()->create();
    $this->actingAs($owner);
    $component = Livewire::test(SupplierListingCreate::class)
        ->set('supplierId', $supplier->id)
        ->set('materialType', 'ingredient')
        ->set('ingredientId', $ingredient->id)
        ->set('purchaseFormat', 'Drum')
        ->set('netQuantity', '1')
        ->set('netUnit', 'kg')
        ->set('priceBasis', ListingPriceBasis::PerUnit->value)
        ->set('priceAmount', '1')
        ->set('priceUnit', 'kg');

    app(ProductionBenchAccess::class)->cancel($owner, $workspace);

    $this->get('/dashboard/production-bench/purchasing/listings/new')->assertForbidden();
    $component->call('save')->assertHasErrors('production_bench');

    expect(SupplierListing::query()->count())->toBe(0);
});

/** @return array{0: User, 1: Workspace} */
function listingCreateWorkspace(MassDisplaySystem $massDisplaySystem = MassDisplaySystem::Metric): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create([
        'mass_display_system' => $massDisplaySystem,
    ]);
    app(ProductionBenchAccess::class)->activate($owner, $workspace);

    return [$owner, $workspace];
}
