<?php

use App\Models\FattyAcid;
use App\Models\Ingredient;
use App\Models\IngredientFattyAcid;
use App\Models\IngredientSapProfile;
use App\Models\ProductFamily;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\RecipePhase;
use App\Models\RecipeVersion;
use App\Services\RecipeWorkbenchService;
use Database\Seeders\FattyAcidSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('uses normalized fatty acid entries in workbench payloads', function () {
    $this->seed(FattyAcidSeeder::class);

    $productFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
    ]);

    $ingredient = Ingredient::factory()->create([
        'display_name' => 'Test Oil',
    ]);

    IngredientSapProfile::factory()->create([
        'ingredient_id' => $ingredient->id,
        'koh_sap_value' => 0.188,
    ]);

    $lauric = FattyAcid::query()->where('key', 'lauric')->firstOrFail();
    $oleic = FattyAcid::query()->where('key', 'oleic')->firstOrFail();

    IngredientFattyAcid::factory()->create([
        'ingredient_id' => $ingredient->id,
        'fatty_acid_id' => $lauric->id,
        'percentage' => 12.5,
    ]);

    IngredientFattyAcid::factory()->create([
        'ingredient_id' => $ingredient->id,
        'fatty_acid_id' => $oleic->id,
        'percentage' => 58.0,
    ]);

    $recipe = Recipe::factory()->create([
        'product_family_id' => $productFamily->id,
        'name' => 'Test Recipe',
    ]);

    $recipeVersion = RecipeVersion::factory()->create([
        'recipe_id' => $recipe->id,
        'name' => 'Test Recipe',
        'is_draft' => true,
        'version_number' => 1,
        'calculation_context' => [
            'editing_mode' => 'percentage',
            'lye_type' => 'naoh',
            'superfat' => 5,
        ],
        'water_settings' => [
            'mode' => 'percent_of_oils',
            'value' => 38,
        ],
    ]);

    $phase = RecipePhase::factory()->create([
        'recipe_version_id' => $recipeVersion->id,
        'name' => 'Saponified oils',
        'slug' => 'saponified_oils',
        'sort_order' => 1,
        'is_system' => true,
    ]);

    RecipeItem::factory()->create([
        'recipe_version_id' => $recipeVersion->id,
        'recipe_phase_id' => $phase->id,
        'ingredient_id' => $ingredient->id,
        'percentage' => 100,
        'weight' => 1000,
        'position' => 1,
    ]);

    $payload = app(RecipeWorkbenchService::class)->draftPayload($recipe);

    expect($payload)->not->toBeNull()
        ->and($payload['phaseItems']['saponified_oils'][0]['fatty_acid_profile'])
        ->toBe([
            'lauric' => 12.5,
            'oleic' => 58.0,
        ]);
});
