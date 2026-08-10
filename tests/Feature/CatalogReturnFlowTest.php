<?php

use App\Enums\IngredientCategory;
use App\Livewire\Dashboard\IngredientEditor;
use App\Livewire\Dashboard\PackagingItemEditor;
use App\Livewire\ProductionBench\Purchasing\SupplierListingCreate;
use App\Models\Ingredient;
use App\Models\PackagingItem;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProductionBenchAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('returns newly created catalog items to a preselected supplier listing', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $supplier = Supplier::factory()->for($workspace)->create();
    app(ProductionBenchAccess::class)->activate($owner, $workspace);
    $this->actingAs($owner);

    Livewire::withQueryParams([
        'return_to' => 'supplier_listing',
        'supplier' => $supplier->public_id,
    ])->test(PackagingItemEditor::class)
        ->set('data.name', 'Amber pump')
        ->set('data.category', 'pump')
        ->set('data.unit_cost', '0.42')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    $packaging = PackagingItem::query()->where('workspace_id', $workspace->id)->sole();

    Livewire::withQueryParams([
        'supplier' => $supplier->public_id,
        'material_type' => 'packaging',
        'packaging_item' => $packaging->public_id,
    ])->test(SupplierListingCreate::class)
        ->assertSet('lockedSupplierPublicId', $supplier->public_id)
        ->assertSet('data.material_type', 'packaging')
        ->assertSet('data.packaging_item_id', $packaging->id);

    Livewire::withQueryParams([
        'return_to' => 'supplier_listing',
        'supplier' => $supplier->public_id,
    ])->test(IngredientEditor::class)
        ->set('data.name', 'Green clay')
        ->set('data.category', IngredientCategory::MineralsSaltsPowders->value)
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    $ingredient = Ingredient::query()->where('display_name', 'Green clay')->sole();

    Livewire::withQueryParams([
        'supplier' => $supplier->public_id,
        'material_type' => 'ingredient',
        'ingredient' => $ingredient->public_id,
    ])->test(SupplierListingCreate::class)
        ->assertSet('data.material_type', 'ingredient')
        ->assertSet('data.ingredient_id', $ingredient->id);
});

it('ignores arbitrary return destinations and foreign supplier context', function (): void {
    $owner = User::factory()->create();
    Workspace::factory()->for($owner, 'owner')->create();
    $foreignSupplier = Supplier::factory()->create();
    $this->actingAs($owner);

    Livewire::withQueryParams([
        'return_to' => 'https://example.com',
        'supplier' => $foreignSupplier->public_id,
    ])->test(PackagingItemEditor::class)
        ->assertSet('returnTo', null)
        ->assertSet('returnSupplierPublicId', null);
});
