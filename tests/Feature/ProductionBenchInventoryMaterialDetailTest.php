<?php

use App\Enums\MassDisplaySystem;
use App\Enums\StockMovementType;
use App\Livewire\ProductionBench\InventoryMaterialDetail;
use App\Models\GoodsReceipt;
use App\Models\Ingredient;
use App\Models\PackagingItem;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierListing;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMaterialSetting;
use App\Services\ProductionBenchAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders a tracked ingredient detail with current position and lot navigation', function (): void {
    ['user' => $user, 'workspace' => $workspace] = materialDetailWorkspace();
    $ingredient = Ingredient::factory()->create(['display_name' => 'Olive oil']);
    $supplier = Supplier::factory()->for($workspace)->create(['name' => 'Local Oils']);
    $listing = SupplierListing::factory()->for($workspace)->for($supplier)->for($ingredient)->create();
    $lot = StockLot::factory()->for($workspace)->for($ingredient)->released()->create([
        'supplier_listing_id' => $listing->id,
        'supplier_batch_number' => 'BATCH-1',
    ]);
    StockMovement::factory()->for($lot, 'stockLot')->create([
        'workspace_id' => $workspace->id,
        'type' => StockMovementType::OpeningBalance,
        'quantity_delta' => '1000',
    ]);
    WorkspaceMaterialSetting::factory()->for($workspace)->for($ingredient)->create([
        'buffer_quantity' => '1200.000000000',
    ]);

    $this->actingAs($user);

    Livewire::test(InventoryMaterialDetail::class, [
        'subject' => $ingredient->public_id,
        'subjectType' => 'ingredient',
    ])
        ->assertSee('Olive oil')
        ->assertSee('Current position')
        ->assertSee('1.00')
        ->assertSee('Local Oils')
        ->assertSee('BATCH-1')
        ->assertSee('View all lots')
        ->assertViewHas('lotRegisterUrl', fn (string $url): bool => str_contains($url, 'material_type=ingredient')
            && str_contains($url, 'lot_scope=all'))
        ->assertViewHas('position', fn (array $position): bool => $position['physical'] === '1.00'
            && $position['available'] === '1.00');
});

it('lists the purchasing listings that can replenish the material', function (): void {
    ['user' => $user, 'workspace' => $workspace] = materialDetailWorkspace();
    // A supplier listing is what makes the material tracked here, so this
    // material has a purchasing catalogue and no stock at all.
    $ingredient = Ingredient::factory()->create(['display_name' => 'Shea butter']);
    $alpha = Supplier::factory()->for($workspace)->create(['name' => 'Alpha Oils']);
    $beta = Supplier::factory()->for($workspace)->create(['name' => 'Beta Supply']);

    SupplierListing::factory()->for($workspace)->for($alpha)->for($ingredient)->create([
        'supplier_sku' => 'SKU-ALPHA',
        'supplier_item_name' => 'Raw shea butter',
        'purchase_format' => 'Drum of 25 kg',
        'is_active' => true,
    ]);
    SupplierListing::factory()->for($workspace)->for($beta)->for($ingredient)->create([
        'supplier_sku' => 'SKU-BETA',
        'supplier_item_name' => 'Refined shea butter',
        'purchase_format' => 'Box of 12 kg',
        'is_active' => false,
    ]);

    // Ingredients are a global catalogue, so the foreign listing shares this
    // subject. Only the workspace scoping keeps it out.
    $foreignWorkspace = Workspace::factory()->for(User::factory(), 'owner')->create();
    SupplierListing::factory()
        ->for($foreignWorkspace)
        ->for(Supplier::factory()->for($foreignWorkspace)->create(['name' => 'Foreign Oils']))
        ->for($ingredient)
        ->create(['supplier_sku' => 'SKU-FOREIGN']);

    $this->actingAs($user);

    Livewire::test(InventoryMaterialDetail::class, [
        'subject' => $ingredient->public_id,
        'subjectType' => 'ingredient',
    ])
        ->assertSee(__('production_bench.inventory.related_supplier_listings'))
        ->assertSee('Alpha Oils')
        ->assertSee('SKU-ALPHA')
        ->assertSee('Raw shea butter')
        ->assertSee('Drum of 25 kg')
        ->assertSee(route('production-bench.purchasing.supplier', $alpha), false)
        ->assertSee('Beta Supply')
        ->assertSee('SKU-BETA')
        ->assertSee('Refined shea butter')
        ->assertSee('Box of 12 kg')
        ->assertSee(route('production-bench.purchasing.supplier', $beta), false)
        // "Active" is a substring of "Inactive", so the badge is matched with
        // its closing tag; otherwise the assertion could never fail.
        ->assertSeeHtml('>'.__('production_bench.common.active').'</span>')
        ->assertSee(__('production_bench.common.inactive'))
        ->assertDontSee('Foreign Oils')
        ->assertDontSee('SKU-FOREIGN')
        // No stock exists, so the listing section has to stand on its own.
        ->assertSee(__('production_bench.inventory.no_open_lots'));
});

it('explains a missing supplier listing without implying that stock is missing', function (): void {
    ['user' => $user, 'workspace' => $workspace] = materialDetailWorkspace();
    $ingredient = Ingredient::factory()->create(['display_name' => 'Cocoa butter']);
    // Tracked by its buffer alone: no lot and no listing.
    WorkspaceMaterialSetting::factory()->for($workspace)->for($ingredient)->create([
        'buffer_quantity' => '1200.000000000',
    ]);

    $this->actingAs($user);

    Livewire::test(InventoryMaterialDetail::class, [
        'subject' => $ingredient->public_id,
        'subjectType' => 'ingredient',
    ])
        ->assertSee(__('production_bench.inventory.related_supplier_listings'))
        ->assertSee(__('production_bench.inventory.no_supplier_listings'))
        ->assertSee(__('production_bench.inventory.no_open_lots'));

    // The empty state has to describe the purchasing catalogue. Reusing the
    // stock wording here would read as "this material has no stock".
    expect(__('production_bench.inventory.no_supplier_listings'))
        ->not->toBe(__('production_bench.inventory.no_open_lots'));
});

it('paginates supplier listings independently of the period activity', function (): void {
    ['user' => $user, 'workspace' => $workspace] = materialDetailWorkspace();
    $ingredient = Ingredient::factory()->create();
    $supplier = Supplier::factory()->for($workspace)->create(['name' => 'Alpha Oils']);

    foreach (range(1, 12) as $n) {
        SupplierListing::factory()->for($workspace)->for($supplier)->for($ingredient)->create([
            'supplier_sku' => 'SKU-'.$n,
        ]);
    }

    $lot = StockLot::factory()->for($workspace)->for($ingredient)->released()->create();

    foreach (range(1, 30) as $n) {
        StockMovement::factory()->for($lot, 'stockLot')->create([
            'workspace_id' => $workspace->id,
            'type' => StockMovementType::PurchaseReceipt,
            'quantity_delta' => '10',
            'occurred_at' => now()->subDays(2)->addHours($n),
        ]);
    }

    $this->actingAs($user);

    Livewire::test(InventoryMaterialDetail::class, [
        'subject' => $ingredient->public_id,
        'subjectType' => 'ingredient',
    ])
        ->assertViewHas('supplierListings', fn (LengthAwarePaginator $page): bool => $page->total() === 12
            && $page->count() === 10
            && $page->lastPage() === 2)
        // Each paginator has to drive its own page size; a shared control would
        // let one section silently rewrite the other's.
        ->assertSeeHtml('wire:model.live="supplierListingsPerPage"')
        ->assertSeeHtml('wire:model.live="perPage"')
        ->call('gotoPage', 2, 'supplier-listings')
        ->assertViewHas('supplierListings', fn (LengthAwarePaginator $page): bool => $page->currentPage() === 2
            && $page->count() === 2)
        // Two paginators share the page, so each has to keep its own position.
        ->assertViewHas('movements', fn (LengthAwarePaginator $page): bool => $page->currentPage() === 1
            && $page->count() === 25)
        ->set('supplierListingsPerPage', 25)
        ->assertViewHas('supplierListings', fn (LengthAwarePaginator $page): bool => $page->currentPage() === 1
            && $page->count() === 12)
        ->assertViewHas('movements', fn (LengthAwarePaginator $page): bool => $page->count() === 25)
        ->set('perPage', 50)
        ->assertViewHas('movements', fn (LengthAwarePaginator $page): bool => $page->count() === 30)
        ->assertViewHas('supplierListings', fn (LengthAwarePaginator $page): bool => $page->count() === 12)
        // A page size that is not offered falls back to the default rather than
        // being handed to the paginator.
        ->set('supplierListingsPerPage', 7)
        ->assertSet('supplierListingsPerPage', 10)
        ->assertViewHas('supplierListings', fn (LengthAwarePaginator $page): bool => $page->count() === 10);
});

it('changes activity periods without changing the current position', function (): void {
    ['user' => $user, 'workspace' => $workspace] = materialDetailWorkspace();
    $ingredient = Ingredient::factory()->create(['display_name' => 'Rose water']);
    $lot = StockLot::factory()->for($workspace)->for($ingredient)->released()->create();
    StockMovement::factory()->for($lot, 'stockLot')->create([
        'workspace_id' => $workspace->id,
        'type' => StockMovementType::OpeningBalance,
        'quantity_delta' => '1000',
        'occurred_at' => now()->subDays(40),
    ]);
    StockMovement::factory()->for($lot, 'stockLot')->create([
        'workspace_id' => $workspace->id,
        'type' => StockMovementType::PurchaseReceipt,
        'quantity_delta' => '250',
        'occurred_at' => now()->subDays(5),
    ]);

    $this->actingAs($user);

    Livewire::test(InventoryMaterialDetail::class, [
        'subject' => $ingredient->public_id,
        'subjectType' => 'ingredient',
    ])
        ->assertViewHas('position', fn (array $position): bool => $position['physical'] === '1.25')
        ->set('periodPreset', '365')
        ->assertViewHas('position', fn (array $position): bool => $position['physical'] === '1.25')
        ->assertViewHas('activity', fn (array $activity): bool => $activity['received'] === '0.25'
            && $activity['reconciliation_ok'] === true);
});

it('paginates the period activity rows while reconciliation covers every movement', function (): void {
    ['user' => $user, 'workspace' => $workspace] = materialDetailWorkspace();
    $ingredient = Ingredient::factory()->create();
    $lot = StockLot::factory()->for($workspace)->for($ingredient)->released()->create();

    foreach (range(1, 30) as $n) {
        StockMovement::factory()->for($lot, 'stockLot')->create([
            'workspace_id' => $workspace->id,
            'type' => StockMovementType::PurchaseReceipt,
            'quantity_delta' => '10',
            'occurred_at' => now()->subDays(2)->addHours($n),
        ]);
    }

    $this->actingAs($user);

    Livewire::test(InventoryMaterialDetail::class, [
        'subject' => $ingredient->public_id,
        'subjectType' => 'ingredient',
    ])
        ->assertViewHas('movements', fn (LengthAwarePaginator $page): bool => $page->total() === 30
            && $page->count() === 25
            && $page->lastPage() === 2)
        ->call('gotoPage', 2, 'activity')
        ->assertViewHas('movements', fn (LengthAwarePaginator $page): bool => $page->currentPage() === 2
            && $page->count() === 5)
        ->set('perPage', 50)
        ->assertViewHas('movements', fn (LengthAwarePaginator $page): bool => $page->currentPage() === 1
            && $page->count() === 30
            && $page->lastPage() === 1)
        // 30 movements of 10 g received = 0.30 kg; the totals never depend on
        // which page of rows is shown.
        ->assertViewHas('activity', fn (array $activity): bool => $activity['received'] === '0.30'
            && $activity['reconciliation_ok'] === true);
});

it('renders packaging balances in units', function (): void {
    ['user' => $user, 'workspace' => $workspace] = materialDetailWorkspace();
    $packaging = PackagingItem::factory()->for($workspace)->create(['name' => 'Clear bottle']);
    $lot = StockLot::factory()
        ->for($workspace)
        ->forPackaging()
        ->released()
        ->create([
            'packaging_item_id' => $packaging->id,
            'unit_kind' => 'count',
        ]);
    StockMovement::factory()->for($lot, 'stockLot')->create([
        'workspace_id' => $workspace->id,
        'type' => StockMovementType::OpeningBalance,
        'quantity_delta' => '12',
        'original_quantity' => '12',
        'original_unit' => 'count',
    ]);
    $this->actingAs($user);

    Livewire::test(InventoryMaterialDetail::class, [
        'subject' => $packaging->public_id,
        'subjectType' => 'packaging',
    ])
        ->assertSee('Clear bottle')
        ->assertSee('units')
        ->assertSee('12')
        ->assertViewHas('position', fn (array $position): bool => $position['physical'] === '12'
        && $position['available'] === '12');
});

it('validates custom activity periods before replacing the period data', function (): void {
    ['user' => $user, 'workspace' => $workspace] = materialDetailWorkspace();
    $ingredient = Ingredient::factory()->create();
    StockLot::factory()->for($workspace)->for($ingredient)->create();
    $this->actingAs($user);

    Livewire::test(InventoryMaterialDetail::class, [
        'subject' => $ingredient->public_id,
        'subjectType' => 'ingredient',
    ])
        ->set('periodPreset', 'custom')
        ->assertHasErrors(['customFrom', 'customTo'])
        ->set('customFrom', '2026-08-31')
        ->set('customTo', '2026-08-01')
        ->assertHasErrors(['customFrom', 'customTo'])
        ->set('customTo', '2026-08-31')
        ->assertHasNoErrors();
});

it('saves and clears a buffer from the detail page', function (): void {
    ['user' => $user, 'workspace' => $workspace] = materialDetailWorkspace();
    $ingredient = Ingredient::factory()->create();
    StockLot::factory()->for($workspace)->for($ingredient)->create();
    $this->actingAs($user);

    Livewire::test(InventoryMaterialDetail::class, [
        'subject' => $ingredient->public_id,
        'subjectType' => 'ingredient',
    ])
        ->callAction('editBuffer', data: ['buffer_quantity' => '1,250.5'])
        ->assertHasNoFormErrors()
        ->assertDispatched('app-notification');

    // 1 250,5 kg in the metric display unit is persisted as canonical grams.
    expect(WorkspaceMaterialSetting::query()->value('buffer_quantity'))->toBe('1250500.000000000');

    Livewire::test(InventoryMaterialDetail::class, [
        'subject' => $ingredient->public_id,
        'subjectType' => 'ingredient',
    ])
        ->callAction('clearBuffer')
        ->assertDispatched('app-notification');

    expect(WorkspaceMaterialSetting::query()->count())->toBe(0);
});

it('clears a buffer by saving an empty quantity', function (): void {
    ['user' => $user, 'workspace' => $workspace] = materialDetailWorkspace();
    $ingredient = Ingredient::factory()->create();
    StockLot::factory()->for($workspace)->for($ingredient)->create();
    WorkspaceMaterialSetting::factory()->for($workspace)->for($ingredient)->create([
        'buffer_quantity' => '1200.000000000',
    ]);
    $this->actingAs($user);

    Livewire::test(InventoryMaterialDetail::class, [
        'subject' => $ingredient->public_id,
        'subjectType' => 'ingredient',
    ])
        ->callAction('editBuffer', data: ['buffer_quantity' => ''])
        ->assertHasNoFormErrors();

    expect(WorkspaceMaterialSetting::query()->count())->toBe(0);
});

it('hides buffer actions for a read-only bench', function (): void {
    ['user' => $user, 'workspace' => $workspace] = materialDetailWorkspace();
    $ingredient = Ingredient::factory()->create();
    StockLot::factory()->for($workspace)->for($ingredient)->create();
    app(ProductionBenchAccess::class)->cancel($user, $workspace);
    $this->actingAs($user);

    Livewire::test(InventoryMaterialDetail::class, [
        'subject' => $ingredient->public_id,
        'subjectType' => 'ingredient',
    ])
        ->assertActionHidden('editBuffer')
        ->assertActionHidden('clearBuffer');
});

it('keeps another workspace material out of the detail route', function (): void {
    ['user' => $user] = materialDetailWorkspace();
    $otherWorkspace = Workspace::factory()->create();
    $ingredient = Ingredient::factory()->create();
    StockLot::factory()->for($otherWorkspace)->for($ingredient)->create();
    $this->actingAs($user);

    $this->get(route('production-bench.inventory.material.ingredient', $ingredient))
        ->assertNotFound();
});

it('rejects mutation of the locked route-bound subject identifiers', function (): void {
    ['user' => $user, 'workspace' => $workspace] = materialDetailWorkspace();
    $ingredient = Ingredient::factory()->create();
    StockLot::factory()->for($workspace)->for($ingredient)->create();
    $this->actingAs($user);

    $component = Livewire::test(InventoryMaterialDetail::class, [
        'subject' => $ingredient->public_id,
        'subjectType' => 'ingredient',
    ]);

    // Without #[Locked] these set() calls would silently succeed and redirect the
    // component to another material on the next request.
    expect(fn () => $component->set('ingredientPublicId', 'tampered'))
        ->toThrow(CannotUpdateLockedPropertyException::class)
        ->and(fn () => $component->set('packagingPublicId', 'tampered'))
        ->toThrow(CannotUpdateLockedPropertyException::class)
        ->and(fn () => $component->set('subjectType', 'packaging'))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('seeds the buffer input in the workspace display unit', function (): void {
    ['user' => $user, 'workspace' => $workspace] = materialDetailWorkspace();
    $ingredient = Ingredient::factory()->create();
    StockLot::factory()->for($workspace)->for($ingredient)->create();
    WorkspaceMaterialSetting::factory()->for($workspace)->for($ingredient)->create([
        'buffer_quantity' => '1200.000000000',
    ]);
    $this->actingAs($user);

    Livewire::test(InventoryMaterialDetail::class, [
        'subject' => $ingredient->public_id,
        'subjectType' => 'ingredient',
    ])
        ->mountAction('editBuffer')
        ->assertSchemaStateSet(['buffer_quantity' => '1.200000000']);
});

it('stores a metric buffer in canonical grams', function (): void {
    ['user' => $user, 'workspace' => $workspace] = materialDetailWorkspace();
    $ingredient = Ingredient::factory()->create();
    StockLot::factory()->for($workspace)->for($ingredient)->create();
    $this->actingAs($user);

    Livewire::test(InventoryMaterialDetail::class, [
        'subject' => $ingredient->public_id,
        'subjectType' => 'ingredient',
    ])
        ->callAction('editBuffer', data: ['buffer_quantity' => '1.2'])
        ->assertHasNoFormErrors();

    expect(WorkspaceMaterialSetting::query()->value('buffer_quantity'))->toBe('1200.000000000');
});

it('stores a us customary buffer in canonical grams', function (): void {
    ['user' => $user, 'workspace' => $workspace] = materialDetailWorkspace(MassDisplaySystem::UsCustomary);
    $ingredient = Ingredient::factory()->create();
    StockLot::factory()->for($workspace)->for($ingredient)->create();
    $this->actingAs($user);

    Livewire::test(InventoryMaterialDetail::class, [
        'subject' => $ingredient->public_id,
        'subjectType' => 'ingredient',
    ])
        ->callAction('editBuffer', data: ['buffer_quantity' => '2'])
        ->assertHasNoFormErrors();

    expect(WorkspaceMaterialSetting::query()->value('buffer_quantity'))->toBe('907.184740000');
});

it('leaves packaging buffers unconverted', function (): void {
    ['user' => $user, 'workspace' => $workspace] = materialDetailWorkspace();
    $packaging = PackagingItem::factory()->for($workspace)->create();
    StockLot::factory()->for($workspace)->forPackaging()->create([
        'packaging_item_id' => $packaging->id,
    ]);
    $this->actingAs($user);

    Livewire::test(InventoryMaterialDetail::class, [
        'subject' => $packaging->public_id,
        'subjectType' => 'packaging',
    ])
        ->callAction('editBuffer', data: ['buffer_quantity' => '12'])
        ->assertHasNoFormErrors();

    expect(WorkspaceMaterialSetting::query()->value('buffer_quantity'))->toBe('12.000000000');
});

it('links only the movement sources that belong to the workspace', function (): void {
    ['user' => $user, 'workspace' => $workspace] = materialDetailWorkspace();
    $ingredient = Ingredient::factory()->create(['display_name' => 'Olive oil']);
    $lot = StockLot::factory()->for($workspace)->for($ingredient)->released()->create();

    $ownReceipt = GoodsReceipt::factory()
        ->direct()
        ->for($workspace)
        ->for(Supplier::factory()->for($workspace), 'supplier')
        ->create(['delivery_reference' => 'DEL-OURS']);

    // The source is a morphTo, so nothing in the schema ties it to the movement's
    // workspace. A foreign record must not surface its identifier here, and its
    // route would 404 at the destination anyway.
    $foreignWorkspace = Workspace::factory()->for(User::factory(), 'owner')->create();
    $foreignReceipt = GoodsReceipt::factory()
        ->direct()
        ->for($foreignWorkspace)
        ->for(Supplier::factory()->for($foreignWorkspace), 'supplier')
        ->create(['delivery_reference' => 'DEL-THEIRS']);

    foreach ([['10', $ownReceipt], ['5', $foreignReceipt]] as [$delta, $receipt]) {
        StockMovement::factory()->for($lot, 'stockLot')->create([
            'workspace_id' => $workspace->id,
            'type' => StockMovementType::PurchaseReceipt,
            'quantity_delta' => $delta,
            'source_type' => $receipt->getMorphClass(),
            'source_id' => $receipt->id,
        ]);
    }

    $this->actingAs($user);

    Livewire::test(InventoryMaterialDetail::class, [
        'subject' => $ingredient->public_id,
        'subjectType' => 'ingredient',
    ])
        ->assertSee('DEL-OURS')
        ->assertDontSee('DEL-THEIRS')
        ->assertSee(__('production_bench.inventory.source_not_available'));
});

/** @return array{user: User, workspace: Workspace} */
function materialDetailWorkspace(MassDisplaySystem $displaySystem = MassDisplaySystem::Metric): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user, 'owner')->create([
        'mass_display_system' => $displaySystem,
    ]);
    app(ProductionBenchAccess::class)->activate($user, $workspace);

    return ['user' => $user, 'workspace' => $workspace];
}
