<?php

use App\Enums\StockMovementType;
use App\Livewire\ProductionBench\InventoryMaterialDetail;
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
        ->set('bufferQuantity', '1,250.5')
        ->call('saveBuffer')
        ->assertDispatched('app-notification')
        ->assertSet('bufferQuantity', '1250.500000000')
        ->set('bufferQuantity', '')
        ->call('saveBuffer')
        ->assertDispatched('app-notification');

    expect(WorkspaceMaterialSetting::query()->count())->toBe(0);
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

/** @return array{user: User, workspace: Workspace} */
function materialDetailWorkspace(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($user, $workspace);

    return ['user' => $user, 'workspace' => $workspace];
}
