<?php

use App\ListingPriceBasis;
use App\Livewire\ProductionBench\Purchasing\SupplierListingCreate;
use App\MassDisplaySystem;
use App\Models\Ingredient;
use App\Models\PackagingItem;
use App\Models\Supplier;
use App\Models\SupplierListing;
use App\Models\User;
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
    PackagingItem::factory()->for($workspace)->create(['name' => 'Amber bottle']);

    $response = $this->actingAs($owner)
        ->get('/dashboard/production-bench/purchasing/listings/new');

    $response
        ->assertOk()
        ->assertSee('New supplier listing')
        ->assertSee('Supplier')
        ->assertSee('Purchase format')
        ->assertSee('Pricing')
        ->assertSee('Calculated price')
        ->assertSee('Purchase formats per order.')
        ->assertSee('Save supplier listing')
        ->assertSeeHtml('class="fi-section')
        ->assertSee('data.supplier_id', escape: false)
        ->assertSeeHtml('inputmode="decimal"')
        ->assertDontSee('Select an existing Soapkraft ingredient.')
        ->assertDontSee('Enter quantity and price.')
        ->assertDontSee('Number of purchase formats.')
        ->assertSeeHtml('href="'.route('production-bench.purchasing.listings').'"');

    expect(substr_count($response->getContent(), '>Cancel</a>'))->toBe(1);

    $component = Livewire::test(SupplierListingCreate::class);

    expect($component->instance()->supplierSearchResults('Olvea'))->toBe([$supplier->id => 'OLVEA · Olvea'])
        ->and($component->instance()->ingredientSearchResults('Olive'))->toHaveCount(1);

    $component->set('data.material_type', 'packaging');
    expect($component->instance()->packagingSearchResults('Amber'))->toHaveCount(1);

    expect(SupplierListing::query()->count())->toBe(0);
});

it('requires a supplier on the global listing form', function (): void {
    [$owner] = listingCreateWorkspace();
    $ingredient = Ingredient::factory()->create();
    $this->actingAs($owner);

    Livewire::test(SupplierListingCreate::class)
        ->set('data.material_type', 'ingredient')
        ->set('data.ingredient_id', $ingredient->id)
        ->set('data.purchase_format', '200 kg drum')
        ->set('data.net_quantity', '200')
        ->set('data.net_unit', 'kg')
        ->set('data.price_basis', ListingPriceBasis::PerUnit->value)
        ->set('data.price_amount', '4.20')
        ->set('data.price_unit', 'kg')
        ->call('save')
        ->assertHasErrors(['data.supplier_id' => 'required']);

    expect(SupplierListing::query()->count())->toBe(0);
});

it('reports all required listing fields together', function (): void {
    [$owner, $workspace] = listingCreateWorkspace();
    $supplier = Supplier::factory()->for($workspace)->create();
    $this->actingAs($owner);

    Livewire::test(SupplierListingCreate::class)
        ->set('data.supplier_id', $supplier->id)
        ->set('data.ingredient_id', null)
        ->set('data.purchase_format', '')
        ->set('data.net_quantity', '')
        ->set('data.price_amount', '')
        ->call('save')
        ->assertHasErrors([
            'data.ingredient_id',
            'data.purchase_format' => 'required',
            'data.net_quantity' => 'required',
            'data.price_amount' => 'required',
        ]);

    expect(SupplierListing::query()->count())->toBe(0);
});

it('creates an exact-price mass listing globally and returns to the listings index', function (): void {
    [$owner, $workspace] = listingCreateWorkspace();
    $supplier = Supplier::factory()->for($workspace)->create(['default_currency' => 'EUR']);
    $ingredient = Ingredient::factory()->create(['display_name' => 'Olive oil']);
    $this->actingAs($owner);

    Livewire::test(SupplierListingCreate::class)
        ->set('data.supplier_id', $supplier->id)
        ->set('data.material_type', 'ingredient')
        ->set('data.ingredient_id', $ingredient->id)
        ->set('data.supplier_sku', 'OO-200')
        ->set('data.supplier_item_name', 'Extra virgin olive oil')
        ->set('data.purchase_format', '200 kg drum')
        ->set('data.net_quantity', '200')
        ->set('data.net_unit', 'kg')
        ->set('data.price_basis', ListingPriceBasis::PerUnit->value)
        ->set('data.price_amount', '4.20')
        ->set('data.price_unit', 'kg')
        ->set('data.currency', 'EUR')
        ->set('data.minimum_packs', 2)
        ->set('data.notes', 'Food grade')
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
        'supplier_item_name' => 'Extra virgin olive oil',
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

it('accepts localized decimal quantities and prices', function (): void {
    [$owner, $workspace] = listingCreateWorkspace();
    $supplier = Supplier::factory()->for($workspace)->create(['default_currency' => 'EUR']);
    $ingredient = Ingredient::factory()->create(['display_name' => 'Olive oil']);
    $this->actingAs($owner);
    app()->setLocale('fr');

    Livewire::test(SupplierListingCreate::class)
        ->fillForm([
            'supplier_id' => $supplier->id,
            'material_type' => 'ingredient',
            'ingredient_id' => $ingredient->id,
            'purchase_format' => '200 kg drum',
            'net_quantity' => '200,00',
            'net_unit' => 'kg',
            'price_basis' => ListingPriceBasis::PerUnit->value,
            'price_amount' => '4,20',
            'price_unit' => 'kg',
            'currency' => 'EUR',
        ])
        ->assertSee('EUR 840.00 total')
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertRedirect(route('production-bench.purchasing.listings'));

    expect(SupplierListing::query()->sole())
        ->net_quantity->toBe('200.000000000')
        ->price_amount->toBe('4.200000000')
        ->total_price->toBe('840.000000000');
});

it('locks supplier context and returns scoped packaging listings to supplier detail', function (): void {
    [$owner, $workspace] = listingCreateWorkspace();
    $supplier = Supplier::factory()->for($workspace)->create(['code' => 'BOTTLES', 'name' => 'Bottle House']);
    $packaging = PackagingItem::factory()->for($workspace)->create(['name' => 'Amber bottle']);
    $this->actingAs($owner);

    $this->get('/dashboard/production-bench/purchasing/suppliers/'.$supplier->public_id.'/listings/new')
        ->assertOk()
        ->assertSee('BOTTLES')
        ->assertSee('Bottle House')
        ->assertSeeHtml('disabled')
        ->assertSeeHtml('href="'.route('production-bench.purchasing.supplier', $supplier).'"');

    Livewire::test(SupplierListingCreate::class, ['supplier' => $supplier->public_id])
        ->assertSet('data.supplier_id', $supplier->id)
        ->set('data.supplier_id', Supplier::factory()->for($workspace)->create()->id)
        ->set('data.material_type', 'packaging')
        ->set('data.packaging_item_id', $packaging->id)
        ->set('data.purchase_format', 'Carton of 500')
        ->set('data.net_quantity', '500')
        ->set('data.price_basis', ListingPriceBasis::TotalPurchaseFormat->value)
        ->set('data.price_amount', '90')
        ->set('data.currency', 'EUR')
        ->assertSee('EUR 0.18 / item')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('production-bench.purchasing.supplier', $supplier));

    $listing = SupplierListing::query()->firstOrFail();

    expect($listing->supplier_id)->toBe($supplier->id)
        ->and($listing->packaging_item_id)->toBe($packaging->id)
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
        ->assertSet('data.net_unit', 'lb')
        ->assertSet('data.price_unit', 'lb')
        ->set('data.supplier_id', $supplier->id)
        ->set('data.material_type', 'ingredient')
        ->set('data.ingredient_id', $ingredient->id)
        ->set('data.purchase_format', '50 lb pail')
        ->set('data.net_quantity', '50')
        ->set('data.net_unit', 'lb')
        ->set('data.price_basis', ListingPriceBasis::TotalPurchaseFormat->value)
        ->set('data.price_amount', '125')
        ->set('data.price_unit', '')
        ->set('data.currency', 'USD')
        ->assertSee('USD 2.50 / lb');
});

it('rejects a currency outside the maintained catalog', function (): void {
    [$owner, $workspace] = listingCreateWorkspace();
    $supplier = Supplier::factory()->for($workspace)->create();
    $ingredient = Ingredient::factory()->create();
    $this->actingAs($owner);

    Livewire::test(SupplierListingCreate::class)
        ->set('data.supplier_id', $supplier->id)
        ->set('data.ingredient_id', $ingredient->id)
        ->set('data.purchase_format', 'Drum')
        ->set('data.net_quantity', '1')
        ->set('data.net_unit', 'kg')
        ->set('data.price_basis', ListingPriceBasis::PerUnit->value)
        ->set('data.price_amount', '1')
        ->set('data.price_unit', 'kg')
        ->set('data.currency', 'ZZZ')
        ->call('save')
        ->assertHasErrors(['data.currency']);

    expect(SupplierListing::query()->count())->toBe(0);
});

it('does not preselect a historical supplier currency for a new listing', function (): void {
    [$owner, $workspace] = listingCreateWorkspace();
    $supplier = Supplier::factory()->for($workspace)->create(['default_currency' => 'HRK']);
    $this->actingAs($owner);

    Livewire::test(SupplierListingCreate::class)
        ->assertSet('data.currency', 'EUR')
        ->set('data.supplier_id', $supplier->id)
        ->assertSet('data.currency', 'EUR');

    Livewire::test(SupplierListingCreate::class, ['supplier' => $supplier->public_id])
        ->assertSet('data.currency', 'EUR');

    $historicalOwner = User::factory()->create();
    $historicalWorkspace = Workspace::factory()->for($historicalOwner, 'owner')->create(['default_currency' => 'HRK']);
    $historicalSupplier = Supplier::factory()->for($historicalWorkspace)->create(['default_currency' => 'HRK']);
    app(ProductionBenchAccess::class)->activate($historicalOwner, $historicalWorkspace);
    $this->actingAs($historicalOwner);

    Livewire::test(SupplierListingCreate::class, ['supplier' => $historicalSupplier->public_id])
        ->assertSet('data.currency', '')
        ->call('save')
        ->assertHasErrors(['data.currency' => 'required']);
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
    $packagingItems = PackagingItem::factory()->count(30)->for($workspace)->sequence(
        fn ($sequence): array => ['name' => sprintf('Packaging %02d', $sequence->index + 1)],
    )->create();
    $selectedSupplier = $suppliers->last();
    $this->actingAs($owner);

    $component = Livewire::test(SupplierListingCreate::class);

    expect($component->instance()->supplierSearchResults(''))->toHaveCount(20)
        ->and($component->instance()->ingredientSearchResults(''))->toHaveCount(20)
        ->and($component->instance()->packagingSearchResults(''))->toBe([]);

    $component
        ->set('data.supplier_id', $selectedSupplier->id);

    expect($component->instance()->supplierSearchResults('no matching supplier'))->toBe([])
        ->and($component->instance()->supplierOptionLabel($selectedSupplier->id))->toBe($selectedSupplier->code.' · '.$selectedSupplier->name);

    $catalogQueries = [];
    DB::listen(function (QueryExecuted $query) use (&$catalogQueries): void {
        $catalogQueries[] = $query->sql;
    });

    $component->instance()->ingredientSearchResults('Ingredient');

    expect(collect($catalogQueries)->contains(
        fn (string $sql): bool => Str::contains($sql, 'from "ingredients"')
            && Str::contains($sql, 'limit 20'),
    ))->toBeTrue()
        ->and(collect($catalogQueries)->contains(
            fn (string $sql): bool => Str::contains($sql, 'packaging_items'),
        ))->toBeFalse();

    $component
        ->set('data.ingredient_id', $ingredients->last()->id);

    expect($component->instance()->ingredientSearchResults('no matching ingredient'))->toBe([])
        ->and($component->instance()->ingredientOptionLabel($ingredients->last()->id))->toBe('Ingredient 30');

    $component->set('data.material_type', 'packaging');

    expect($component->instance()->ingredientSearchResults(''))->toBe([])
        ->and($component->instance()->packagingSearchResults(''))->toHaveCount(20);

    $component
        ->set('data.packaging_item_id', $packagingItems->last()->id);

    expect($component->instance()->packagingSearchResults('no matching packaging item'))->toBe([])
        ->and($component->instance()->packagingOptionLabel($packagingItems->last()->id))->toBe('Packaging 30');
});

it('does not requery supplier or material catalogs for price preview updates', function (): void {
    [$owner, $workspace] = listingCreateWorkspace();
    $supplier = Supplier::factory()->for($workspace)->create(['code' => 'SELECTED', 'name' => 'Selected supplier']);
    $ingredient = Ingredient::factory()->create(['display_name' => 'Selected ingredient']);
    Supplier::factory()->count(2)->for($workspace)->create();
    Ingredient::factory()->count(2)->create();
    PackagingItem::factory()->count(3)->for($workspace)->create();
    $this->actingAs($owner);

    $component = Livewire::test(SupplierListingCreate::class)
        ->set('data.supplier_id', $supplier->id)
        ->set('data.ingredient_id', $ingredient->id);
    $selectorQueries = [];

    DB::listen(function (QueryExecuted $query) use (&$selectorQueries): void {
        if (Str::contains($query->sql, ['suppliers', 'ingredients', 'ingredient_translations', 'packaging_items'])) {
            $selectorQueries[] = $query->sql;
        }
    });

    $component
        ->set('data.net_quantity', '2')
        ->set('data.price_amount', '4.25')
        ->assertSee('SELECTED · Selected supplier')
        ->assertSee('Selected ingredient');

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
    $foreignPackaging = PackagingItem::factory()->create(['name' => 'Foreign bottle']);
    $this->actingAs($owner);

    $this->get('/dashboard/production-bench/purchasing/suppliers/'.$foreignSupplier->public_id.'/listings/new')
        ->assertNotFound();

    Livewire::test(SupplierListingCreate::class)
        ->assertDontSee('Foreign Supplier')
        ->assertDontSee('Foreign oil')
        ->assertDontSee('Foreign bottle')
        ->set('data.supplier_id', $foreignSupplier->id)
        ->set('data.material_type', 'ingredient')
        ->set('data.ingredient_id', $foreignIngredient->id)
        ->set('data.purchase_format', 'Drum')
        ->set('data.net_quantity', '1')
        ->set('data.net_unit', 'kg')
        ->set('data.price_basis', ListingPriceBasis::PerUnit->value)
        ->set('data.price_amount', '1')
        ->set('data.price_unit', 'kg')
        ->call('save')
        ->assertHasErrors('data.supplier_id');

    Livewire::test(SupplierListingCreate::class)
        ->set('data.supplier_id', $supplier->id)
        ->set('data.material_type', 'packaging')
        ->set('data.packaging_item_id', $foreignPackaging->id)
        ->set('data.purchase_format', 'Carton')
        ->set('data.net_quantity', '1')
        ->set('data.price_basis', ListingPriceBasis::PerUnit->value)
        ->set('data.price_amount', '1')
        ->call('save')
        ->assertHasErrors('data.packaging_item_id');

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
        ->set('data.supplier_id', $supplier->id)
        ->set('data.material_type', 'ingredient')
        ->set('data.ingredient_id', $ingredient->id)
        ->set('data.purchase_format', 'Drum')
        ->set('data.net_quantity', '1')
        ->set('data.net_unit', 'kg')
        ->set('data.price_basis', ListingPriceBasis::PerUnit->value)
        ->set('data.price_amount', '1')
        ->set('data.price_unit', 'kg');

    app(ProductionBenchAccess::class)->cancel($owner, $workspace);

    $this->get('/dashboard/production-bench/purchasing/listings/new')->assertForbidden();
    $component->call('save')->assertHasErrors('data.production_bench');

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
