<?php

use App\Enums\IngredientCategory;
use App\Enums\IngredientSubcategory;
use App\Enums\ProductionRunStatus;
use App\Enums\StockLotOrigin;
use App\Enums\StockMovementType;
use App\Enums\StockReservationStatus;
use App\Enums\StockUnitKind;
use App\Livewire\ProductionBench\HomeIndex;
use App\Livewire\ProductionBench\InventoryIndex;
use App\Livewire\ProductionBench\PurchasingIndex;
use App\Models\Ingredient;
use App\Models\PackagingItem;
use App\Models\ProductionRequirement;
use App\Models\ProductionRun;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\StockReservation;
use App\Models\Supplier;
use App\Models\SupplierListing;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMaterialSetting;
use App\Services\MassConverter;
use App\Services\ProductionBenchAccess;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

uses(RefreshDatabase::class);

it('uses factual production bench copy', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($user, $workspace);

    $response = $this->actingAs($user)
        ->get(route('production-bench.home'));

    $response
        ->assertOk()
        ->assertSee('Active')
        ->assertSee('Quarantined')
        ->assertSee('Incoming')
        ->assertSeeHtml('class="grid gap-4 md:grid-cols-2"')
        ->assertDontSee('Bench is active')
        ->assertDontSee('lots waiting for release')
        ->assertDontSee('orders still incoming')
        ->assertDontSee('Optional professional workspace')
        ->assertDontSee('Production without the ERP headache.')
        ->assertDontSee('Ready when your production grows')
        ->assertDontSee('next checkpoint');

    expect(substr_count($response->getContent(), 'sk-card p-5 transition hover:shadow-lg'))->toBe(2)
        ->and($response->getContent())->not->toContain('grid gap-px overflow-hidden rounded-2xl');

    $this->get(route('production-bench.inventory'))
        ->assertOk()
        ->assertSee('Inventory')
        ->assertSee('Stock by material')
        ->assertSee('Compare physical stock with what is available')
        ->assertDontSee('what production can actually use')
        ->assertDontSee('What is here, and what is usable.');
});

it('exposes the production bench as a separate authenticated workspace', function (): void {
    $this->get('/dashboard/production-bench')->assertRedirect('/login');

    $user = User::factory()->create();
    Workspace::factory()->for($user, 'owner')->create();

    $this->actingAs($user)
        ->get(route('production-bench.home'))
        ->assertOk()
        ->assertSee('Production Bench')
        ->assertSee('Activate Production Bench');

    $this->get(route('production-bench.inventory'))
        ->assertOk()
        ->assertSee('Inactive')
        ->assertSee('Production Bench')
        ->assertDontSee('Activate the bench to start inventory.')
        ->assertDontSee('Go to Production Bench home');
});

it('activates and later preserves a read only bench', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user, 'owner')->create();
    $this->actingAs($user);

    Livewire::test(HomeIndex::class)
        ->call('activate')
        ->assertSee('Active')
        ->assertDontSee('Bench is active');

    expect(app(ProductionBenchAccess::class)->isActive($workspace))->toBeTrue();

    Livewire::test(HomeIndex::class)
        ->call('cancel')
        ->assertSee('Read-only');

    Livewire::test(InventoryIndex::class)
        ->assertSee('Read-only. Resume to edit.')
        ->assertDontSee('all stock history is preserved')
        ->assertDontSee('Resume the Production Bench to post changes.');

    expect(app(ProductionBenchAccess::class)->isReadOnly($workspace))->toBeTrue();
});

it('keeps manual stock entry out of the material view', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($user, $workspace);
    Ingredient::factory()->create(['display_name' => 'Olive oil']);

    $this->actingAs($user)
        ->get(route('production-bench.inventory'))
        ->assertOk()
        ->assertDontSee('Opening stock')
        ->assertDontSee('Add stock manually')
        ->assertDontSee('Stock positions')
        ->assertSee('Stock by material')
        ->assertSee('Available')
        ->assertDontSee('Add a lot already on your shelves')
        ->assertDontSee('No lots yet. Add the stock already on your shelves above.');

    $this->actingAs($user)
        ->get(route('production-bench.purchasing'))
        ->assertRedirectToRoute('production-bench.purchasing.suppliers');

    $this->actingAs($user)
        ->get(route('production-bench.purchasing.suppliers'))
        ->assertOk()
        ->assertSee('Suppliers')
        ->assertDontSee('Receive delivery');

    Livewire::test(InventoryIndex::class)->assertOk();
    Livewire::test(PurchasingIndex::class)
        ->assertOk()
        ->assertDontSeeHtml('<h1')
        ->assertDontSeeHtml('class="flex flex-wrap gap-3"');
});

it('offers two inventory sections for material positions and lot stock', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($user, $workspace);

    $this->actingAs($user)
        ->get(route('production-bench.inventory.stock'))
        ->assertOk()
        ->assertSee('Lot register')
        ->assertDontSee('Opening stock')
        ->assertSee('Add stock manually')
        ->assertSee('Physical')
        ->assertSee('Stock by material');

    $this->get(route('production-bench.inventory'))
        ->assertOk()
        ->assertSee('Stock by material')
        ->assertSee('Required')
        ->assertSee('Reserved')
        ->assertSee('Forecast')
        ->assertSee('Lot register');
});

it('gives each inventory page a translated browser title of its own', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($user, $workspace);

    // Both pages share one Livewire component, so the browser title is the only
    // thing that tells them apart in history and in a tab strip.
    $this->actingAs($user)
        ->get(route('production-bench.inventory'))
        ->assertOk()
        ->assertSee('<title>'.__('production_bench.inventory.stock_by_material').' · '.config('app.name').'</title>', false);

    $this->get(route('production-bench.inventory.stock'))
        ->assertOk()
        ->assertSee('<title>'.__('production_bench.inventory.lot_register').' · '.config('app.name').'</title>', false);
});

it('links every material identity to its accessible detail page', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($user, $workspace);
    $supplier = Supplier::factory()->for($workspace)->create();
    $ingredient = Ingredient::factory()->create(['display_name' => 'Olive oil']);
    $packaging = PackagingItem::factory()->for($workspace)->create(['name' => 'Amber bottle']);
    SupplierListing::factory()->for($workspace)->for($supplier)->for($ingredient)->create();
    SupplierListing::factory()->for($workspace)->for($supplier)->create([
        'ingredient_id' => null,
        'packaging_item_id' => $packaging->id,
        'unit_kind' => StockUnitKind::Count,
        'purchase_format' => 'Box of 100 units',
        'canonical_quantity_per_purchase_format' => '100',
        'net_quantity' => '100',
        'net_unit' => 'count',
    ]);

    $this->actingAs($user);

    Livewire::test(InventoryIndex::class, ['mode' => 'materials'])
        ->assertSee(route('production-bench.inventory.material.ingredient', $ingredient), false)
        ->assertSee(route('production-bench.inventory.material.packaging', $packaging), false)
        ->assertSee(__('production_bench.inventory.open_material_detail'), false);

    $this->get(route('production-bench.inventory.material.ingredient', $ingredient))
        ->assertSee('Olive oil');
    $this->get(route('production-bench.inventory.material.packaging', $packaging))
        ->assertSee('Amber bottle');
});

it('selects a material explicitly in the lot register and links it to detail', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($user, $workspace);
    $olive = Ingredient::factory()->create(['display_name' => 'Olive oil']);
    $coconut = Ingredient::factory()->create(['display_name' => 'Coconut oil']);
    StockLot::factory()->for($workspace)->for($olive)->create();
    StockLot::factory()->for($workspace)->for($coconut)->create();

    $this->actingAs($user);

    Livewire::test(InventoryIndex::class, ['mode' => 'stock'])
        ->set('lotScope', 'all')
        // The combobox is a Filament schema component, so assert it is actually
        // rendered and labelled rather than trusting the method to exist.
        ->assertSeeHtml('for="lotFiltersForm.lotMaterialSelection"')
        ->call('selectLotMaterial', 'ingredient:'.$olive->public_id)
        ->assertSet('lotMaterialType', 'ingredient')
        ->assertSet('lotMaterial', $olive->public_id)
        ->assertSee('Olive oil')
        ->assertDontSee('Coconut oil')
        ->assertSee(route('production-bench.inventory.material.ingredient', $olive), false);
});

it('rejects lot register material selections the workspace cannot reach', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($user, $workspace);
    $foreignWorkspace = Workspace::factory()->create();
    $foreignPackaging = PackagingItem::factory()->for($foreignWorkspace)->create(['name' => 'Foreign jar']);
    $untrackedIngredient = Ingredient::factory()->create(['display_name' => 'Untracked wax']);

    $this->actingAs($user);

    // Livewire renders its own error responses, so the abort only reaches the
    // test once Laravel stops handling exceptions.
    $this->withoutExceptionHandling();

    // A packaging item owned by another workspace is not a valid selection.
    expect(fn () => Livewire::test(InventoryIndex::class, ['mode' => 'stock'])
        ->call('selectLotMaterial', 'packaging:'.$foreignPackaging->public_id))
        ->toThrow(NotFoundHttpException::class);

    // An ingredient the workspace does not track is not a valid selection either.
    expect(fn () => Livewire::test(InventoryIndex::class, ['mode' => 'stock'])
        ->call('selectLotMaterial', 'ingredient:'.$untrackedIngredient->public_id))
        ->toThrow(NotFoundHttpException::class);

    // A compound key naming a subject type that does not exist is malformed.
    expect(fn () => Livewire::test(InventoryIndex::class, ['mode' => 'stock'])
        ->call('selectLotMaterial', 'recipe:'.$untrackedIngredient->public_id))
        ->toThrow(HttpException::class);
});

it('ignores a malformed lot material filter instead of querying with it', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($user, $workspace);
    $olive = Ingredient::factory()->create(['display_name' => 'Olive oil']);
    StockLot::factory()->for($workspace)->for($olive)->create();

    $this->actingAs($user);

    // `public_id` is a uuid column. On PostgreSQL a non-uuid value is not an
    // empty result, it is an "invalid input syntax for type uuid" failure, so
    // the filter has to be rejected before it reaches a query.
    Livewire::withQueryParams(['material' => 'not-a-uuid', 'material_type' => 'ingredient'])
        ->test(InventoryIndex::class, ['mode' => 'stock'])
        ->set('lotScope', 'all')
        ->assertSet('lotMaterial', '')
        ->assertSet('lotMaterialType', '')
        ->assertSee('Olive oil');
});

it('rejects a malformed compound selection without querying for it', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($user, $workspace);

    $this->actingAs($user);
    $this->withoutExceptionHandling();

    expect(fn () => Livewire::test(InventoryIndex::class, ['mode' => 'stock'])
        ->call('selectLotMaterial', 'ingredient:not-a-uuid'))
        ->toThrow(NotFoundHttpException::class);
});

it('never offers another workspace material to the lot register combobox', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($user, $workspace);
    $supplier = Supplier::factory()->for($workspace)->create();
    $foreignWorkspace = Workspace::factory()->create();
    $owned = Ingredient::factory()->create(['display_name' => 'Olive oil']);
    $foreign = Ingredient::factory()->create(['display_name' => 'Foreign wax']);
    SupplierListing::factory()->for($workspace)->for($supplier)->for($owned)->create();
    SupplierListing::factory()->for($foreignWorkspace)
        ->for(Supplier::factory()->for($foreignWorkspace))
        ->for($foreign)
        ->create();

    $this->actingAs($user);

    Livewire::test(InventoryIndex::class, ['mode' => 'stock'])
        ->call('lotMaterialSearchResults', 'oil')
        ->assertReturned(['ingredient:'.$owned->public_id => 'Olive oil']);

    Livewire::test(InventoryIndex::class, ['mode' => 'stock'])
        ->call('lotMaterialSearchResults', 'wax')
        ->assertReturned([]);
});

it('clears the lot register material filter when the combobox is emptied', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($user, $workspace);
    $olive = Ingredient::factory()->create(['display_name' => 'Olive oil']);
    StockLot::factory()->for($workspace)->for($olive)->create();

    $this->actingAs($user);

    Livewire::test(InventoryIndex::class, ['mode' => 'stock'])
        ->set('lotScope', 'all')
        ->call('selectLotMaterial', 'ingredient:'.$olive->public_id)
        ->assertSet('lotMaterial', $olive->public_id)
        ->call('selectLotMaterial', null)
        ->assertSet('lotMaterial', '')
        ->assertSet('lotMaterialType', '')
        ->assertSee('Olive oil');
});

it('redirects the retired material requirements page to the material view', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($user, $workspace);

    $this->actingAs($user)
        ->get(route('production-bench.inventory.requirements'))
        ->assertRedirectToRoute('production-bench.inventory');
});

it('sorts material shortages first in the material view', function (): void {
    ['user' => $user] = plannedShortageWorkspace();

    $this->actingAs($user);

    Livewire::test(InventoryIndex::class, ['mode' => 'materials'])
        ->assertViewHas('materials', fn ($materials): bool => $materials->total() === 2
            && $materials->first()['name'] === 'Short oil')
        ->assertSee('Short oil')
        ->assertSee('Covered oil');
});

it('narrows the material view to shortages only', function (): void {
    ['user' => $user] = plannedShortageWorkspace();

    $this->actingAs($user);

    Livewire::test(InventoryIndex::class, ['mode' => 'materials'])
        ->set('stockState', 'negative_forecast')
        ->assertViewHas('materials', fn ($materials): bool => $materials->total() === 1
            && $materials->first()['name'] === 'Short oil')
        ->assertSee('Short oil')
        ->assertDontSee('Covered oil');
});

it('activates and clears the negative forecast filter from the shortage summary tile', function (): void {
    ['user' => $user] = plannedShortageWorkspace();

    $this->actingAs($user);

    // The summary tile is the shortcut into the same state the filter panel
    // exposes, so it has to move stockState and not just repaint the count.
    Livewire::test(InventoryIndex::class, ['mode' => 'materials'])
        ->assertSet('stockState', 'all')
        ->call('toggleShortageFilter')
        ->assertSet('stockState', 'negative_forecast')
        ->assertViewHas('materials', fn ($materials): bool => $materials->total() === 1
            && $materials->first()['name'] === 'Short oil')
        ->assertDontSee('Covered oil')
        ->call('toggleShortageFilter')
        ->assertSet('stockState', 'all')
        ->assertViewHas('materials', fn ($materials): bool => $materials->total() === 2);
});

it('gives a below buffer row its own warning state', function (): void {
    ['user' => $user] = belowBufferWorkspace();

    $this->actingAs($user);

    // Below buffer and negative forecast are different facts: this row has
    // 1000 g against a 1200 g buffer and no demand at all, so the forecast
    // stays positive and only the buffer state is true.
    Livewire::test(InventoryIndex::class, ['mode' => 'materials'])
        ->assertViewHas('materials', fn ($materials): bool => $materials->total() === 1
            && $materials->first()['is_below_buffer'] === true
            && $materials->first()['is_shortage'] === false)
        // The row tint carries the `/40` modifier, which the text badge does
        // not, so this cannot be satisfied by the badge alone.
        ->assertSeeHtml('bg-[var(--color-warning-soft)]/40')
        ->assertDontSeeHtml('bg-[var(--color-danger-soft)]/40')
        // Colour is never the only signal.
        ->assertSee('Below buffer');
});

it('keeps danger precedence when a row is both a shortage and below buffer', function (): void {
    ['user' => $user, 'workspace' => $workspace] = belowBufferWorkspace();

    $deepIngredient = Ingredient::factory()->create(['display_name' => 'Deep oil']);
    WorkspaceMaterialSetting::factory()->for($workspace)->for($deepIngredient)->create([
        'buffer_quantity' => '1200.000000000',
    ]);
    $production = ProductionRun::factory()->for($workspace)->create([
        'status' => ProductionRunStatus::Scheduled,
    ]);
    ProductionRequirement::factory()->for($production)->for($deepIngredient)->create([
        'required_mass_grams' => '2000.000000000',
        'sort_order' => 1,
    ]);
    $lot = StockLot::factory()->for($workspace)->for($deepIngredient)->released()->create();
    StockMovement::factory()->for($lot, 'stockLot')->create([
        'quantity_delta' => '1000.000000000',
    ]);

    $this->actingAs($user);

    Livewire::test(InventoryIndex::class, ['mode' => 'materials'])
        ->set('stockState', 'negative_forecast')
        ->assertViewHas('materials', fn ($materials): bool => $materials->total() === 1
            && $materials->first()['name'] === 'Deep oil'
            // 1000 g available against 1200 g of buffer and 2000 g demanded:
            // both states are true at once.
            && $materials->first()['is_shortage'] === true
            && $materials->first()['is_below_buffer'] === true)
        ->assertSeeHtml('bg-[var(--color-danger-soft)]/40')
        // Danger wins the row; the buffer is still reported in text.
        ->assertDontSeeHtml('bg-[var(--color-warning-soft)]/40')
        ->assertSee('Below buffer')
        ->assertSee('Negative forecast');
});

it('narrows the material view to materials with and without planned demand', function (): void {
    ['user' => $user, 'workspace' => $workspace] = plannedShortageWorkspace();
    $supplier = Supplier::factory()->for($workspace)->create();
    $unplanned = Ingredient::factory()->create(['display_name' => 'Listed oil']);
    SupplierListing::factory()->for($workspace)->for($supplier)->for($unplanned)->create();

    $this->actingAs($user);

    Livewire::test(InventoryIndex::class, ['mode' => 'materials'])
        ->set('demandFilter', 'unplanned')
        ->assertViewHas('materials', fn ($materials): bool => $materials->total() === 1
            && $materials->first()['name'] === 'Listed oil')
        ->set('demandFilter', 'planned')
        ->assertViewHas('materials', fn ($materials): bool => $materials->total() === 2);
});

it('paginates the material table', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($user, $workspace);
    $supplier = Supplier::factory()->for($workspace)->create();

    foreach (range(1, 26) as $index) {
        $ingredient = Ingredient::factory()->create(['display_name' => 'Listed oil '.$index]);
        SupplierListing::factory()->for($workspace)->for($supplier)->for($ingredient)->create();
    }

    $this->actingAs($user);

    Livewire::test(InventoryIndex::class, ['mode' => 'materials'])
        ->assertViewHas('materials', fn ($materials): bool => $materials instanceof LengthAwarePaginator
            && $materials->count() === 25
            && $materials->total() === 26);
});

it('counts the material summary tiles from the filtered rows', function (): void {
    ['user' => $user, 'workspace' => $workspace] = plannedShortageWorkspace();
    $supplier = Supplier::factory()->for($workspace)->create();
    $unplanned = Ingredient::factory()->create(['display_name' => 'Listed oil']);
    SupplierListing::factory()->for($workspace)->for($supplier)->for($unplanned)->create();

    $this->actingAs($user);

    // The tiles and the table read the same filtered set, so the counts stay
    // in step when a scope narrows the table.
    Livewire::test(InventoryIndex::class, ['mode' => 'materials'])
        ->assertViewHas('inventorySummary', fn (array $summary): bool => $summary === [
            'materials' => 3,
            'shortages' => 1,
            'incoming' => 0,
            'quarantined' => 0,
            'unplanned' => 1,
            'below_buffer' => 0,
        ])
        ->set('stockState', 'negative_forecast')
        ->assertViewHas('inventorySummary', fn (array $summary): bool => $summary['materials'] === 1
            && $summary['shortages'] === 1
            && $summary['unplanned'] === 0);
});

it('paginates the stock lot register', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($user, $workspace);
    $ingredient = Ingredient::factory()->create(['display_name' => 'Olive oil']);
    StockLot::factory()->count(26)->for($workspace)->for($ingredient)->released()->create();
    $this->actingAs($user);

    Livewire::test(InventoryIndex::class, ['mode' => 'stock'])
        ->set('lotScope', 'all')
        ->assertViewHas('lots', fn ($lots): bool => $lots instanceof LengthAwarePaginator
            && $lots->count() === 25
            && $lots->total() === 26);
});

it('filters the stock lot register by material and status', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($user, $workspace);
    $olive = Ingredient::factory()->create(['display_name' => 'Olive oil']);
    $coconut = Ingredient::factory()->create(['display_name' => 'Coconut oil']);
    StockLot::factory()->for($workspace)->for($olive)->create();
    StockLot::factory()->for($workspace)->for($olive)->released()->create();
    StockLot::factory()->for($workspace)->for($coconut)->create();
    $this->actingAs($user);

    Livewire::test(InventoryIndex::class, ['mode' => 'stock'])
        ->set('lotScope', 'all')
        ->set('search', 'Olive')
        ->set('lotStatus', 'quarantined')
        ->assertSee('Olive oil')
        ->assertDontSee('Coconut oil')
        ->assertViewHas('lots', fn (LengthAwarePaginator $lots): bool => $lots->total() === 1);
});

it('filters the lot register by scope supplier origin dates and expiry', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($user, $workspace);
    $ingredient = Ingredient::factory()->create([
        'display_name' => 'Olive oil',
        'inci_name' => 'OLEA EUROPAEA FRUIT OIL',
    ]);
    $supplier = Supplier::factory()->for($workspace)->create(['name' => 'Local Oils']);
    $listing = SupplierListing::factory()->for($workspace)->for($supplier)->for($ingredient)->create();
    $openLot = StockLot::factory()->for($workspace)->for($ingredient)->released()->create([
        'supplier_listing_id' => $listing->id,
        'origin' => StockLotOrigin::PurchaseReceipt,
        'stocked_at' => today()->subDays(3),
        'expires_at' => today()->addDays(30),
    ]);
    StockMovement::factory()->for($openLot, 'stockLot')->create([
        'workspace_id' => $workspace->id,
        'type' => StockMovementType::OpeningBalance,
        'quantity_delta' => '10',
    ]);
    $expiredLot = StockLot::factory()->for($workspace)->for($ingredient)->released()->create([
        'origin' => StockLotOrigin::OpeningBalance,
        'stocked_at' => today()->subDays(20),
        'expires_at' => today()->subDay(),
    ]);
    StockMovement::factory()->for($expiredLot, 'stockLot')->create([
        'workspace_id' => $workspace->id,
        'type' => StockMovementType::OpeningBalance,
        'quantity_delta' => '5',
    ]);
    StockLot::factory()->for($workspace)->for($ingredient)->released()->create([
        'origin' => StockLotOrigin::OpeningBalance,
        'stocked_at' => today(),
    ]);

    $this->actingAs($user);

    Livewire::test(InventoryIndex::class, ['mode' => 'stock'])
        ->assertViewHas('lots', fn (LengthAwarePaginator $lots): bool => $lots->total() === 2)
        ->set('lotExpiry', 'active')
        ->assertViewHas('lots', fn (LengthAwarePaginator $lots): bool => $lots->total() === 1)
        ->set('lotExpiry', 'expired')
        ->assertViewHas('lots', fn (LengthAwarePaginator $lots): bool => $lots->total() === 1)
        ->set('lotExpiry', 'all')
        ->set('lotScope', 'exhausted')
        ->assertViewHas('lots', fn (LengthAwarePaginator $lots): bool => $lots->total() === 1)
        ->set('lotScope', 'all')
        ->set('lotSupplier', $supplier->public_id)
        ->assertViewHas('lots', fn (LengthAwarePaginator $lots): bool => $lots->total() === 1)
        ->set('lotOrigin', StockLotOrigin::PurchaseReceipt->value)
        ->set('lotStockedFrom', today()->subDays(4)->toDateString())
        ->set('lotStockedUntil', today()->subDays(2)->toDateString())
        ->set('lotExpiry', 'all')
        ->assertViewHas('lots', fn (LengthAwarePaginator $lots): bool => $lots->total() === 1)
        ->set('search', 'OLEA EUROPAEA')
        ->assertViewHas('lots', fn (LengthAwarePaginator $lots): bool => $lots->total() === 1)
        ->assertSee('Local Oils');
});

it('renders the material filter controls from a filament schema', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($user, $workspace);

    $this->actingAs($user);

    // The `for` attribute carries the schema name, so these ids only exist
    // while the controls are rendered by materialFiltersForm() and
    // materialAdvancedFiltersForm() rather than by handwritten markup.
    Livewire::test(InventoryIndex::class, ['mode' => 'materials'])
        ->assertSeeHtml('for="materialFiltersForm.search"')
        ->assertSeeHtml('for="materialFiltersForm.sort"')
        ->assertSeeHtml('aria-controls="material-advanced-filters"')
        ->assertSeeHtml('id="material-advanced-filters"')
        ->assertSeeHtml('x-show="filtersOpen"')
        ->assertSeeHtml('for="materialAdvancedFiltersForm.materialType"')
        ->assertSeeHtml('for="materialAdvancedFiltersForm.stockState"')
        ->assertSeeHtml('for="materialAdvancedFiltersForm.demandFilter"')
        ->assertSeeHtml('for="materialAdvancedFiltersForm.categoryFilter"')
        ->assertSeeHtml('for="materialAdvancedFiltersForm.subcategoryFilter"')
        // Priority ordering has no direction to invert, so the control stays
        // hidden until a sort that can be reversed is chosen.
        ->assertDontSeeHtml('for="materialFiltersForm.direction"')
        ->set('sort', 'name')
        ->assertSeeHtml('for="materialFiltersForm.direction"');
});

it('renders the lot register filter controls from a filament schema', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($user, $workspace);

    $this->actingAs($user);

    Livewire::test(InventoryIndex::class, ['mode' => 'stock'])
        ->assertSeeHtml('for="lotFiltersForm.lotScope"')
        ->assertSeeHtml('for="lotFiltersForm.lotStatus"')
        ->assertSeeHtml('for="lotFiltersForm.lotSupplier"')
        ->assertSeeHtml('for="lotFiltersForm.lotOrigin"')
        ->assertSeeHtml('for="lotFiltersForm.lotStockedFrom"')
        ->assertSeeHtml('for="lotFiltersForm.lotStockedUntil"')
        ->assertSeeHtml('for="lotFiltersForm.lotExpiry"')
        ->assertSeeHtml('for="lotFiltersForm.lotSort"');
});

it('constrains the subcategory options to the chosen category', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($user, $workspace);

    $this->actingAs($user);

    Livewire::test(InventoryIndex::class, ['mode' => 'materials'])
        // No category means no subcategory to choose from.
        ->assertViewHas('subcategoryOptions', fn (array $options): bool => $options === [])
        ->set('categoryFilter', IngredientCategory::Lipids->value)
        ->assertViewHas(
            'subcategoryOptions',
            fn (array $options): bool => array_key_exists(IngredientSubcategory::VegetableOils->value, $options)
                && ! array_key_exists(IngredientSubcategory::Anionic->value, $options),
        )
        ->set('subcategoryFilter', IngredientSubcategory::VegetableOils->value)
        ->assertSet('subcategoryFilter', IngredientSubcategory::VegetableOils->value)
        // A subcategory from the old category cannot survive the category
        // changing underneath it.
        ->set('categoryFilter', IngredientCategory::Surfactants->value)
        ->assertSet('subcategoryFilter', '')
        ->assertViewHas(
            'subcategoryOptions',
            fn (array $options): bool => array_key_exists(IngredientSubcategory::Anionic->value, $options)
                && ! array_key_exists(IngredientSubcategory::VegetableOils->value, $options),
        );
});

it('keeps the url bound properties canonical while filtering through the schemas', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($user, $workspace);
    $ingredient = Ingredient::factory()->create(['display_name' => 'Shea butter']);
    $lot = StockLot::factory()->for($workspace)->for($ingredient)->released()->create();
    StockMovement::factory()->for($lot, 'stockLot')->create([
        'workspace_id' => $workspace->id,
        'type' => StockMovementType::OpeningBalance,
        'quantity_delta' => '10',
    ]);
    $exhausted = StockLot::factory()->for($workspace)->for($ingredient)->released()->create();
    StockMovement::factory()->for($exhausted, 'stockLot')->create([
        'workspace_id' => $workspace->id,
        'type' => StockMovementType::OpeningBalance,
        'quantity_delta' => '5',
    ]);
    StockMovement::factory()->for($exhausted, 'stockLot')->create([
        'workspace_id' => $workspace->id,
        'type' => StockMovementType::ProductionConsumption,
        'quantity_delta' => '-5',
    ]);

    $this->actingAs($user);

    // The schemas must drive these exact properties, not a nested state array,
    // so a bookmarked URL keeps filtering exactly as it did before.
    Livewire::test(InventoryIndex::class, ['mode' => 'stock'])
        ->assertSet('lotScope', 'open')
        ->assertViewHas('lots', fn (LengthAwarePaginator $lots): bool => $lots->total() === 1)
        ->set('lotScope', 'all')
        ->assertSet('lotScope', 'all')
        ->assertViewHas('lots', fn (LengthAwarePaginator $lots): bool => $lots->total() === 2)
        ->set('search', 'Shea butter')
        ->assertSet('search', 'Shea butter')
        ->assertViewHas('lots', fn (LengthAwarePaginator $lots): bool => $lots->total() === 2);
});

it('keeps reserved zero-balance lots in the default open scope', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($user, $workspace);
    $ingredient = Ingredient::factory()->create();

    // Net-zero movements: the lot is physically empty.
    $reservedLot = StockLot::factory()->for($workspace)->for($ingredient)->released()->create();
    StockMovement::factory()->for($reservedLot, 'stockLot')->create([
        'workspace_id' => $workspace->id,
        'type' => StockMovementType::OpeningBalance,
        'quantity_delta' => '5',
    ]);
    StockMovement::factory()->for($reservedLot, 'stockLot')->create([
        'workspace_id' => $workspace->id,
        'type' => StockMovementType::ProductionConsumption,
        'quantity_delta' => '-5',
    ]);
    StockReservation::factory()->for($workspace)->for($reservedLot, 'stockLot')->create([
        'quantity' => '2.000000000',
        'status' => StockReservationStatus::Active,
    ]);

    // Net-zero movements and nothing reserved: genuinely exhausted.
    $exhaustedLot = StockLot::factory()->for($workspace)->for($ingredient)->released()->create();
    StockMovement::factory()->for($exhaustedLot, 'stockLot')->create([
        'workspace_id' => $workspace->id,
        'type' => StockMovementType::OpeningBalance,
        'quantity_delta' => '3',
    ]);
    StockMovement::factory()->for($exhaustedLot, 'stockLot')->create([
        'workspace_id' => $workspace->id,
        'type' => StockMovementType::ProductionConsumption,
        'quantity_delta' => '-3',
    ]);

    $this->actingAs($user);

    Livewire::test(InventoryIndex::class, ['mode' => 'stock'])
        ->assertViewHas('lots', fn (LengthAwarePaginator $lots): bool => $lots->total() === 1
            && $lots->first()['lot']->id === $reservedLot->id)
        ->set('lotScope', 'exhausted')
        ->assertViewHas('lots', fn (LengthAwarePaginator $lots): bool => $lots->total() === 1
            && $lots->first()['lot']->id === $exhaustedLot->id)
        ->set('lotScope', 'all')
        ->assertViewHas('lots', fn (LengthAwarePaginator $lots): bool => $lots->total() === 2);
});

it('keeps subject forecasts out of the lot register', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($user, $workspace);
    $this->actingAs($user);

    Livewire::test(InventoryIndex::class, ['mode' => 'stock'])
        ->assertSee(__('production_bench.inventory.physical'))
        ->assertSee(__('production_bench.inventory.available'))
        ->assertDontSee(__('production_bench.inventory.incoming'))
        ->assertDontSee(__('production_bench.inventory.forecast'));
});

it('lists a material the workspace can buy before any run asks for it', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($user, $workspace);
    $supplier = Supplier::factory()->for($workspace)->create();
    $listed = Ingredient::factory()->create(['display_name' => 'Listed oil']);
    SupplierListing::factory()->for($workspace)->for($supplier)->for($listed)->create();
    $this->actingAs($user);

    Livewire::test(InventoryIndex::class, ['mode' => 'materials'])
        ->assertViewHas('materials', fn ($materials): bool => $materials->total() === 1
            && $materials->first()['name'] === 'Listed oil'
            && $materials->first()['has_demand'] === false
            && $materials->first()['has_listing'] === true)
        ->assertSee('Listed oil')
        ->assertSee('No planned demand');
});

it('uses unit-of-measure wording for legacy packaging listing validation', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($user, $workspace);
    $supplier = Supplier::factory()->for($workspace)->create();
    $packaging = PackagingItem::factory()->for($workspace)->create();
    $this->actingAs($user);

    $component = Livewire::test(PurchasingIndex::class)
        ->set('listingSupplierId', $supplier->id)
        ->set('listingSubjectType', 'packaging')
        ->set('listingSubjectId', $packaging->id)
        ->set('listingDescription', 'Carton')
        ->set('listingQuantity', '1.5')
        ->set('listingPackPrice', '10');

    $validationException = null;

    try {
        $component->instance()->createListing(
            app(ProductionBenchAccess::class),
            app(MassConverter::class),
        );
    } catch (ValidationException $exception) {
        $validationException = $exception;
    }

    expect($validationException)->toBeInstanceOf(ValidationException::class)
        ->and($validationException?->errors())->toBe([
            'listingQuantity' => ['Packaging quantity must be a whole number.'],
        ])
        ->and(SupplierListing::query()->count())->toBe(0);
});

/**
 * An active bench with two planned ingredients: one covered by stock and one
 * short, so the material view has something to order and something to filter.
 *
 * @return array{user: User, workspace: Workspace, coveredIngredient: Ingredient, shortIngredient: Ingredient}
 */
it('normalizes tampered filter query-string values to safe defaults', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($user, $workspace);

    $this->actingAs($user);

    Livewire::withQueryParams([
        'type' => 'bogus',
        'state' => 'bogus',
        'demand' => 'bogus',
        'category' => 'bogus',
        'subcategory' => 'bogus',
        'sort' => 'bogus',
        'direction' => 'bogus',
        'material_type' => 'bogus',
        'lot_scope' => 'bogus',
        'status' => 'bogus',
        'origin' => 'bogus',
        'stocked_from' => 'not-a-date',
        'stocked_until' => '2026-02-30',
        'expiry' => 'bogus',
        'lot_sort' => 'bogus',
    ])->test(InventoryIndex::class)
        ->assertSet('materialType', 'all')
        ->assertSet('stockState', 'all')
        ->assertSet('demandFilter', 'all')
        ->assertSet('categoryFilter', '')
        ->assertSet('subcategoryFilter', '')
        ->assertSet('sort', 'priority')
        ->assertSet('direction', 'asc')
        ->assertSet('lotMaterialType', '')
        ->assertSet('lotScope', 'open')
        ->assertSet('lotStatus', 'all')
        ->assertSet('lotOrigin', '')
        ->assertSet('lotStockedFrom', '')
        ->assertSet('lotStockedUntil', '')
        ->assertSet('lotExpiry', 'all')
        ->assertSet('lotSort', 'newest');
});

it('ignores tampered mid-session filter values instead of erroring', function (): void {
    ['user' => $user] = plannedShortageWorkspace();

    $this->actingAs($user);

    // The query layer allow-lists every filter, so an injected value behaves as
    // the neutral default instead of filtering, erroring, or leaking.
    Livewire::test(InventoryIndex::class, ['mode' => 'materials'])
        ->set('stockState', 'bogus-injected')
        ->assertSee('Covered oil')
        ->assertSee('Short oil')
        ->set('stockState', 'negative_forecast')
        ->assertDontSee('Covered oil')
        ->assertSee('Short oil')
        ->set('stockState', 'bogus-injected')
        ->set('categoryFilter', 'bogus')
        ->set('subcategoryFilter', 'bogus')
        ->set('sort', 'bogus')
        ->set('direction', 'bogus')
        ->assertSee('Covered oil')
        ->assertSee('Short oil')
        ->set('perPage', 1000)
        ->assertViewHas('materials', fn ($materials): bool => $materials->perPage() === 25);
});

it('normalizes tampered lot date filters on update', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($user, $workspace);
    $ingredient = Ingredient::factory()->create();
    StockLot::factory()->for($workspace)->for($ingredient)->released()->create();

    $this->actingAs($user);

    // Lot dates are the only filter values that reach SQL (whereDate) without an
    // allow-list; an unparseable value must never reach the query.
    Livewire::test(InventoryIndex::class, ['mode' => 'stock'])
        ->set('lotStockedFrom', 'not-a-date')
        ->assertSet('lotStockedFrom', '')
        ->set('lotStockedUntil', '2026-02-30')
        ->assertSet('lotStockedUntil', '');
});

/**
 * One ingredient sitting below its configured buffer with no demand, so the
 * buffer state is true while the forecast stays positive.
 */
function belowBufferWorkspace(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($user, $workspace);

    $ingredient = Ingredient::factory()->create(['display_name' => 'Buffer oil']);
    WorkspaceMaterialSetting::factory()->for($workspace)->for($ingredient)->create([
        'buffer_quantity' => '1200.000000000',
    ]);

    $lot = StockLot::factory()->for($workspace)->for($ingredient)->released()->create();
    StockMovement::factory()->for($lot, 'stockLot')->create([
        'quantity_delta' => '1000.000000000',
    ]);

    return [
        'user' => $user,
        'workspace' => $workspace,
        'ingredient' => $ingredient,
    ];
}

function plannedShortageWorkspace(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($user, $workspace);
    $coveredIngredient = Ingredient::factory()->create(['display_name' => 'Covered oil']);
    $shortIngredient = Ingredient::factory()->create(['display_name' => 'Short oil']);
    $production = ProductionRun::factory()->for($workspace)->create([
        'status' => ProductionRunStatus::Scheduled,
    ]);

    ProductionRequirement::factory()->for($production)->for($coveredIngredient)->create([
        'required_mass_grams' => '100.000000000',
        'sort_order' => 1,
    ]);
    ProductionRequirement::factory()->for($production)->for($shortIngredient)->create([
        'required_mass_grams' => '500.000000000',
        'sort_order' => 2,
    ]);

    $coveredLot = StockLot::factory()->for($workspace)->for($coveredIngredient)->released()->create();
    StockMovement::factory()->for($coveredLot, 'stockLot')->create([
        'quantity_delta' => '1000.000000000',
    ]);

    return [
        'user' => $user,
        'workspace' => $workspace,
        'coveredIngredient' => $coveredIngredient,
        'shortIngredient' => $shortIngredient,
    ];
}
