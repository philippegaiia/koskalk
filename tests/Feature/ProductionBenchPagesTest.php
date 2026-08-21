<?php

use App\Enums\ProductionRunStatus;
use App\Livewire\ProductionBench\HomeIndex;
use App\Livewire\ProductionBench\InventoryIndex;
use App\Livewire\ProductionBench\PurchasingIndex;
use App\Models\Ingredient;
use App\Models\PackagingItem;
use App\Models\ProductionRequirement;
use App\Models\ProductionRun;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierListing;
use App\Models\User;
use App\Models\Workspace;
use App\Services\MassConverter;
use App\Services\ProductionBenchAccess;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

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
        ->assertSee('Physical includes quarantined stock.')
        ->assertSee('Negative balances are allowed.')
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

it('keeps manual stock entry out of the inventory overview', function (): void {
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
        ->assertSee('Material requirements')
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

it('offers clear inventory sections for stock and material requirements', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($user, $workspace);

    $this->actingAs($user)
        ->get(route('production-bench.inventory.stock'))
        ->assertOk()
        ->assertSee('Stock')
        ->assertDontSee('Opening stock')
        ->assertSee('Add stock manually')
        ->assertSee('Stock positions')
        ->assertSee('Requirements');

    $this->get(route('production-bench.inventory.requirements'))
        ->assertOk()
        ->assertSee('Requirements')
        ->assertSee('Required')
        ->assertSee('Reserved')
        ->assertSee('Stock');
});

it('keeps the inventory overview focused on projected shortages', function (): void {
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

    $this->actingAs($user);

    Livewire::test(InventoryIndex::class, ['mode' => 'overview'])
        ->assertViewHas('overviewShortages', function ($shortages) use ($shortIngredient): bool {
            return $shortages instanceof Collection
                && $shortages->count() === 1
                && $shortages->first()['subject']->is($shortIngredient);
        })
        ->assertSee('Short oil')
        ->assertDontSee('Covered oil');
});

it('paginates the stock lot register', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($user, $workspace);
    $ingredient = Ingredient::factory()->create(['display_name' => 'Olive oil']);
    StockLot::factory()->count(26)->for($workspace)->for($ingredient)->released()->create();
    $this->actingAs($user);

    Livewire::test(InventoryIndex::class, ['mode' => 'stock'])
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
        ->set('filters.search', 'Olive')
        ->set('filters.status', 'quarantined')
        ->assertSee('Olive oil')
        ->assertDontSee('Coconut oil')
        ->assertViewHas('lots', fn (LengthAwarePaginator $lots): bool => $lots->total() === 1);
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

it('prioritizes material shortages in the requirements view', function (): void {
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
    $this->actingAs($user);

    Livewire::test(InventoryIndex::class, ['mode' => 'requirements'])
        ->assertViewHas('forecast', fn ($forecast): bool => $forecast->first()['subject']->is($shortIngredient));
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
