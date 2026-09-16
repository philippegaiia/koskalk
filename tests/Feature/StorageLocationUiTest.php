<?php

use App\Enums\GoodsReceiptSource;
use App\Enums\ProcurementStage;
use App\Enums\PurchaseOrderStatus;
use App\Enums\StockMovementType;
use App\Livewire\ProductionBench\InventoryIndex;
use App\Livewire\ProductionBench\InventoryMaterialDetail;
use App\Livewire\ProductionBench\Purchasing\ReceiptCreate;
use App\Models\Ingredient;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\StorageLocation;
use App\Models\Supplier;
use App\Models\SupplierListing;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMaterialSetting;
use App\Services\ProductionBenchAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('shows storage controls only when the workspace enables them', function (): void {
    [$owner, $workspace] = storageUiWorkspace();
    $ingredient = Ingredient::factory()->create(['display_name' => 'UI storage oil']);
    $lot = storageUiLot($workspace, $ingredient, 'UI-OFF');

    $this->actingAs($owner);

    Livewire::test(InventoryIndex::class, ['mode' => 'stock'])
        ->assertActionHidden('changeStorageLocation', ['lot_id' => $lot->id])
        ->assertDontSeeHtml('data-storage-location-column')
        ->assertDontSeeHtml('data-lot-storage-location-filter');

    Livewire::test(InventoryMaterialDetail::class, [
        'subject' => $ingredient->public_id,
        'subjectType' => 'ingredient',
    ])
        ->assertActionHidden('editStorageLocation')
        ->assertDontSeeHtml('data-material-storage-location');

    $supplier = Supplier::factory()->for($workspace)->create();
    $listing = SupplierListing::factory()->for($workspace)->for($supplier)->for($ingredient)->create();

    Livewire::withQueryParams(['source' => GoodsReceiptSource::Direct->value])
        ->test(ReceiptCreate::class)
        ->set('supplierId', $supplier->id)
        ->assertDontSeeHtml('data-receipt-storage-location="'.$listing->id.'"');

    $workspace->update(['uses_storage_locations' => true]);
    $owner->forgetAccessibleWorkspaceIds();
    $location = StorageLocation::factory()->for($workspace)->create(['name' => 'Oil shelf']);

    Livewire::test(InventoryIndex::class, ['mode' => 'stock'])
        ->assertActionVisible('changeStorageLocation', ['lot_id' => $lot->id])
        ->assertSeeHtml('data-storage-location-column')
        ->assertSee($location->name);

    Livewire::test(InventoryMaterialDetail::class, [
        'subject' => $ingredient->public_id,
        'subjectType' => 'ingredient',
    ])
        ->assertActionVisible('editStorageLocation')
        ->assertSeeHtml('data-material-storage-location');

    Livewire::withQueryParams(['source' => GoodsReceiptSource::Direct->value])
        ->test(ReceiptCreate::class)
        ->set('supplierId', $supplier->id)
        ->assertSeeHtml('data-receipt-storage-location="'.$listing->id.'"')
        ->assertSee($location->name);
});

it('prefills the usual location for opening stock and allows an explicit clear', function (): void {
    [$owner, $workspace] = storageUiWorkspace(enabled: true);
    $ingredient = Ingredient::factory()->create();
    $location = StorageLocation::factory()->for($workspace)->create(['name' => 'Oil shelf']);
    WorkspaceMaterialSetting::factory()->for($workspace)->for($ingredient)->create([
        'default_storage_location_id' => $location->id,
        'buffer_quantity' => null,
    ]);
    $supplier = Supplier::factory()->for($workspace)->create();
    $listing = SupplierListing::factory()->for($workspace)->for($supplier)->for($ingredient)->create();

    $this->actingAs($owner);

    $component = Livewire::test(InventoryIndex::class, ['mode' => 'stock'])
        ->mountAction('addStock')
        ->set('mountedActions.0.data.supplier_listing_id', $listing->id);

    $component->assertSchemaStateSet(['storage_location_id' => (string) $location->id]);

    Livewire::test(InventoryIndex::class, ['mode' => 'stock'])
        ->mountAction('addStock')
        ->set('mountedActions.0.data.supplier_listing_id', $listing->id)
        ->fillForm([
            'quantity' => '1',
            'unit' => 'kg',
            'price_per_unit' => '10',
            'stocked_at' => today()->toDateString(),
            'storage_location_id' => $location->id,
        ])
        ->callMountedAction()
        ->assertHasNoFormErrors();

    expect(StockLot::query()->sole()->storage_location_id)->toBe($location->id);

    Livewire::test(InventoryIndex::class, ['mode' => 'stock'])
        ->mountAction('addStock')
        ->set('mountedActions.0.data.supplier_listing_id', $listing->id)
        ->fillForm([
            'quantity' => '1',
            'unit' => 'kg',
            'price_per_unit' => '10',
            'stocked_at' => today()->toDateString(),
            'storage_location_id' => null,
        ])
        ->callMountedAction()
        ->assertHasNoFormErrors();

    expect(StockLot::query()->latest('id')->first()->storage_location_id)->toBeNull();
});

it('prefills the usual location on purchase order and direct receipt lines', function (): void {
    [$owner, $workspace] = storageUiWorkspace(enabled: true);
    $ingredient = Ingredient::factory()->create();
    $location = StorageLocation::factory()->for($workspace)->create(['name' => 'Receiving shelf']);
    WorkspaceMaterialSetting::factory()->for($workspace)->for($ingredient)->create([
        'default_storage_location_id' => $location->id,
        'buffer_quantity' => null,
    ]);
    $supplier = Supplier::factory()->for($workspace)->create();
    $listing = SupplierListing::factory()->for($workspace)->for($supplier)->for($ingredient)->create();
    $order = PurchaseOrder::factory()->for($workspace)->for($supplier)->create([
        'stage' => ProcurementStage::PurchaseOrder,
        'status' => PurchaseOrderStatus::Ordered,
        'issued_at' => now(),
        'created_by_user_id' => $owner->id,
    ]);
    $line = PurchaseOrderLine::factory()->for($order)->for($listing, 'supplierListing')->create([
        'ingredient_id' => $ingredient->id,
        'ordered_packs' => 1,
        'expected_quantity' => $listing->canonical_quantity_per_purchase_format,
    ]);

    $this->actingAs($owner);

    Livewire::withQueryParams([
        'source' => GoodsReceiptSource::PurchaseOrder->value,
        'order' => $order->public_id,
    ])
        ->test(ReceiptCreate::class)
        ->assertSet("lineInputs.{$line->id}.storage_location_id", (string) $location->id)
        ->assertSeeHtml('data-receipt-storage-location="'.$line->id.'"');

    Livewire::withQueryParams(['source' => GoodsReceiptSource::Direct->value])
        ->test(ReceiptCreate::class)
        ->set('supplierId', $supplier->id)
        ->assertSet("lineInputs.{$listing->id}.storage_location_id", (string) $location->id)
        ->assertSeeHtml('data-receipt-storage-location="'.$listing->id.'"');
});

it('edits material defaults and reassigns lots from the register', function (): void {
    [$owner, $workspace] = storageUiWorkspace(enabled: true);
    $ingredient = Ingredient::factory()->create(['display_name' => 'Reassignable oil']);
    $first = StorageLocation::factory()->for($workspace)->create(['name' => 'Old shelf']);
    $second = StorageLocation::factory()->for($workspace)->create(['name' => 'New shelf']);
    WorkspaceMaterialSetting::factory()->for($workspace)->for($ingredient)->create([
        'default_storage_location_id' => $first->id,
        'buffer_quantity' => null,
    ]);
    $lot = storageUiLot($workspace, $ingredient, 'UI-REASSIGN', $first->id);

    $this->actingAs($owner);

    Livewire::test(InventoryMaterialDetail::class, [
        'subject' => $ingredient->public_id,
        'subjectType' => 'ingredient',
    ])
        ->mountAction('editStorageLocation')
        ->assertSchemaStateSet(['storage_location_id' => $first->id]);

    Livewire::test(InventoryMaterialDetail::class, [
        'subject' => $ingredient->public_id,
        'subjectType' => 'ingredient',
    ])
        ->callAction('editStorageLocation', data: ['storage_location_id' => null])
        ->assertDispatched('app-notification');

    expect(WorkspaceMaterialSetting::query()->value('default_storage_location_id'))->toBeNull();

    Livewire::test(InventoryIndex::class, ['mode' => 'stock'])
        ->callAction('changeStorageLocation', [
            'storage_location_id' => $second->id,
        ], ['lot_id' => $lot->id])
        ->assertDispatched('app-notification');

    expect($lot->fresh()->storage_location_id)->toBe($second->id);
});

it('filters lots by an assigned or unassigned location while retaining archived names', function (): void {
    [$owner, $workspace] = storageUiWorkspace(enabled: true);
    $ingredient = Ingredient::factory()->create();
    $active = StorageLocation::factory()->for($workspace)->create(['name' => 'Active shelf']);
    $archived = StorageLocation::factory()->for($workspace)->create(['name' => 'Archived shelf', 'is_active' => false]);
    $assigned = storageUiLot($workspace, $ingredient, 'UI-ACTIVE', $active->id);
    $archivedLot = storageUiLot($workspace, $ingredient, 'UI-ARCHIVED', $archived->id);
    $unassigned = storageUiLot($workspace, $ingredient, 'UI-UNASSIGNED');

    $this->actingAs($owner);

    Livewire::test(InventoryIndex::class, ['mode' => 'stock'])
        ->assertSee($archived->name)
        ->set('lotStorageLocation', (string) $active->id)
        ->assertSee($assigned->internal_lot_code)
        ->assertDontSee($archivedLot->internal_lot_code)
        ->assertDontSee($unassigned->internal_lot_code)
        ->set('lotStorageLocation', 'unassigned')
        ->assertSee($unassigned->internal_lot_code)
        ->assertDontSee($assigned->internal_lot_code);
});

/** @return array{0: User, 1: Workspace} */
function storageUiWorkspace(bool $enabled = false): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create([
        'uses_storage_locations' => $enabled,
    ]);
    app(ProductionBenchAccess::class)->activate($owner, $workspace);

    return [$owner, $workspace];
}

function storageUiLot(Workspace $workspace, Ingredient $ingredient, string $code, ?int $locationId = null): StockLot
{
    $lot = StockLot::factory()->for($workspace)->for($ingredient)->released()->create([
        'internal_lot_code' => $code,
        'storage_location_id' => $locationId,
    ]);
    StockMovement::factory()->for($lot, 'stockLot')->create([
        'workspace_id' => $workspace->id,
        'type' => StockMovementType::OpeningBalance,
        'quantity_delta' => '1000',
    ]);

    return $lot;
}
