<?php

use App\Enums\OwnerType;
use App\Livewire\Dashboard\IngredientsIndex;
use App\Livewire\Dashboard\PackagingItemsIndex;
use App\Livewire\ProductionBench\InventoryIndex;
use App\Livewire\ProductionBench\InventoryMaterialDetail;
use App\Livewire\ProductionBench\Production\BatchSizeForm;
use App\Livewire\ProductionBench\Production\BatchSizeIndex;
use App\Livewire\ProductionBench\Production\ProductionIndex;
use App\Livewire\ProductionBench\Production\TaskIndex;
use App\Livewire\ProductionBench\Production\TaskSetForm;
use App\Livewire\ProductionBench\Production\TaskSetIndex;
use App\Livewire\ProductionBench\Purchasing\ProcurementIndex;
use App\Livewire\ProductionBench\Purchasing\ReceiptIndex;
use App\Livewire\ProductionBench\Purchasing\SupplierDetail;
use App\Livewire\ProductionBench\Purchasing\SupplierIndex;
use App\Livewire\ProductionBench\Purchasing\SupplierListingIndex;
use App\Models\GoodsReceipt;
use App\Models\Ingredient;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProductionBenchAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('accepts ten rows and resets pagination on custom tables', function (string $component, string $viewKey, array $parameters): void {
    $actor = User::factory()->create();
    $workspace = Workspace::factory()->for($actor, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($actor, $workspace);
    $actor->update(['active_workspace_id' => $workspace->id]);
    $this->actingAs($actor);

    if ($component === InventoryMaterialDetail::class) {
        $ingredient = Ingredient::factory()->create();
        $lot = StockLot::factory()->for($workspace)->for($ingredient)->released()->create();
        StockMovement::factory()->for($lot, 'stockLot')->create(['workspace_id' => $workspace->id]);
        $parameters = ['subject' => $ingredient->public_id, 'subjectType' => 'ingredient'];
    }
    if ($component === SupplierDetail::class) {
        $parameters = ['supplier' => Supplier::factory()->for($workspace)->create()->public_id];
    }
    if ($component === IngredientsIndex::class) {
        Ingredient::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $actor->id]);
    }
    if ($component === PackagingItemsIndex::class) {
        createPackagingItemForWorkspace(['user_id' => $actor->id, 'workspace_id' => $workspace->id, 'name' => 'Test box', 'unit_cost' => '1.00']);
    }
    if ($component === ReceiptIndex::class) {
        GoodsReceipt::factory()->direct()->for($workspace)->for(Supplier::factory()->for($workspace))->create();
    }

    $page = Livewire::test($component, $parameters)
        ->assertSeeHtml('<option value="10">10</option>');
    $paginator = $page->viewData($viewKey);
    $page->call('gotoPage', 2, $paginator->getPageName())
        ->set('perPage', 10)
        ->assertSet('perPage', 10)
        ->assertViewHas($viewKey, fn ($rows): bool => $rows->perPage() === 10 && $rows->currentPage() === 1)
        ->set('perPage', 13)
        ->assertSet('perPage', 25);
})->with([
    'ingredients' => [IngredientsIndex::class, 'ingredients', []],
    'packaging' => [PackagingItemsIndex::class, 'items', []],
    'stock by material' => [InventoryIndex::class, 'materials', ['mode' => 'materials']],
    'lot register' => [InventoryIndex::class, 'lots', ['mode' => 'stock']],
    'period activity' => [InventoryMaterialDetail::class, 'movements', []],
    'productions' => [ProductionIndex::class, 'productions', []],
    'tasks' => [TaskIndex::class, 'tasks', []],
    'batch sizes' => [BatchSizeIndex::class, 'presets', []],
    'batch size recipes' => [BatchSizeForm::class, 'recipes', []],
    'task sets' => [TaskSetIndex::class, 'taskSets', []],
    'task set recipes' => [TaskSetForm::class, 'recipes', []],
    'suppliers' => [SupplierIndex::class, 'suppliers', []],
    'supplier listings' => [SupplierListingIndex::class, 'listingRows', []],
    'supplier detail' => [SupplierDetail::class, 'listingRows', []],
    'receipts' => [ReceiptIndex::class, 'receipts', []],
    'purchase orders' => [ProcurementIndex::class, 'orders', ['stage' => 'purchase_order']],
    'quotations' => [ProcurementIndex::class, 'orders', ['stage' => 'quotation']],
]);
