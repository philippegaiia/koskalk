<?php

use App\Enums\ProductionRequirementKind;
use App\Enums\ProductionRunStatus;
use App\Enums\StockUnitKind;
use App\Models\Ingredient;
use App\Models\PackagingItem;
use App\Models\ProductionRequirement;
use App\Models\ProductionRun;
use App\Models\Supplier;
use App\Models\SupplierListing;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Production\WorkspaceMaterialCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('includes a material only once when it is both planned and listed', function (): void {
    ['workspace' => $workspace, 'ingredient' => $ingredient] = materialCatalogFixture();
    $supplier = Supplier::factory()->for($workspace)->create();
    SupplierListing::factory()->for($workspace)->for($supplier)->for($ingredient)->create();
    $production = ProductionRun::factory()->for($workspace)->create([
        'status' => ProductionRunStatus::Scheduled,
    ]);
    ProductionRequirement::factory()->for($production, 'productionRun')->for($ingredient)->create([
        'kind' => ProductionRequirementKind::Ingredient,
        'required_mass_grams' => '100.000000000',
    ]);

    $materials = app(WorkspaceMaterialCatalog::class)->materials($workspace);

    expect($materials)->toHaveCount(1)
        ->and($materials->first()['key'])->toBe('ingredient:'.$ingredient->id)
        ->and($materials->first()['has_demand'])->toBeTrue()
        ->and($materials->first()['has_listing'])->toBeTrue();
});

it('includes a listed material that no planned run asks for', function (): void {
    ['workspace' => $workspace, 'ingredient' => $ingredient] = materialCatalogFixture();
    $supplier = Supplier::factory()->for($workspace)->create();
    SupplierListing::factory()->for($workspace)->for($supplier)->for($ingredient)->create();

    $materials = app(WorkspaceMaterialCatalog::class)->materials($workspace);

    expect($materials)->toHaveCount(1)
        ->and($materials->first()['has_demand'])->toBeFalse()
        ->and($materials->first()['has_listing'])->toBeTrue();
});

it('keeps a material whose only listing is deactivated', function (): void {
    ['workspace' => $workspace, 'ingredient' => $ingredient] = materialCatalogFixture();
    $supplier = Supplier::factory()->for($workspace)->create();
    SupplierListing::factory()->for($workspace)->for($supplier)->for($ingredient)->create([
        'is_active' => false,
    ]);

    // Retiring a listing is how purchasing stops offering it. Stock can still
    // sit against it, so it stays evidence the workspace uses the material.
    expect(app(WorkspaceMaterialCatalog::class)->materials($workspace))->toHaveCount(1);
});

it('ignores requirements on runs that no longer demand stock', function (): void {
    ['workspace' => $workspace, 'ingredient' => $ingredient] = materialCatalogFixture();

    foreach ([ProductionRunStatus::Draft, ProductionRunStatus::Completed, ProductionRunStatus::Cancelled] as $status) {
        $production = ProductionRun::factory()->for($workspace)->create(['status' => $status]);
        ProductionRequirement::factory()->for($production, 'productionRun')->for($ingredient)->create([
            'kind' => ProductionRequirementKind::Ingredient,
            'required_mass_grams' => '100.000000000',
        ]);
    }

    expect(app(WorkspaceMaterialCatalog::class)->materials($workspace))->toBeEmpty();
});

it('applies the same rule to workspace owned packaging', function (): void {
    ['workspace' => $workspace] = materialCatalogFixture();
    $supplier = Supplier::factory()->for($workspace)->create();
    $listedPackaging = PackagingItem::factory()->for($workspace)->create(['name' => 'Soap box']);
    // Packaging listings are counted, never weighed, and the catalogue
    // trigger rejects anything else.
    SupplierListing::factory()->for($workspace)->for($supplier)->create([
        'ingredient_id' => null,
        'packaging_item_id' => $listedPackaging->id,
        'unit_kind' => StockUnitKind::Count,
    ]);

    $materials = app(WorkspaceMaterialCatalog::class)->materials($workspace);

    expect($materials)->toHaveCount(1)
        ->and($materials->first()['key'])->toBe('packaging:'.$listedPackaging->id);
});

it('excludes another workspace packaging item and listing', function (): void {
    ['workspace' => $workspace] = materialCatalogFixture();
    $other = materialCatalogFixture();
    $supplier = Supplier::factory()->for($other['workspace'])->create();
    $foreignPackaging = PackagingItem::factory()->for($other['workspace'])->create(['name' => 'Foreign box']);
    SupplierListing::factory()->for($other['workspace'])->for($supplier)->create([
        'ingredient_id' => null,
        'packaging_item_id' => $foreignPackaging->id,
        'unit_kind' => StockUnitKind::Count,
    ]);

    expect(app(WorkspaceMaterialCatalog::class)->materials($workspace))->toBeEmpty();
});

/**
 * @return array{workspace: Workspace, ingredient: Ingredient}
 */
function materialCatalogFixture(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $ingredient = Ingredient::factory()->create(['display_name' => 'Olive oil']);

    return ['workspace' => $workspace, 'ingredient' => $ingredient];
}
