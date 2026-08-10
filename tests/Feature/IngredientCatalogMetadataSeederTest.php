<?php

use App\Enums\IngredientCategory;
use App\Enums\IngredientSubcategory;
use App\Models\Ingredient;
use Database\Seeders\IngredientCatalogMetadataSeeder;
use Database\Seeders\IngredientFunctionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('reapplies exact taxonomy metadata idempotently without inventing CosIng provenance', function () {
    $this->seed(IngredientFunctionSeeder::class);

    $ingredient = Ingredient::factory()->create([
        'catalog_key' => 'OB1',
        'category' => IngredientCategory::Lipids,
        'display_name' => 'Olive Oil',
        'inci_name' => 'OLEA EUROPAEA FRUIT OIL',
        'owner_type' => null,
    ]);

    $this->seed(IngredientCatalogMetadataSeeder::class);
    $this->seed(IngredientCatalogMetadataSeeder::class);

    $ingredient = $ingredient->fresh('functions');

    expect($ingredient->category)->toBe(IngredientCategory::Lipids)
        ->and($ingredient->subcategory)->toBe(IngredientSubcategory::VegetableOils)
        ->and($ingredient->cosing_reference)->toBeNull()
        ->and($ingredient->functions)->toBeEmpty();
});
