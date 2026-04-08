<?php

use App\IngredientCategory;
use App\Models\Ingredient;
use App\Models\ProductFamily;
use App\Models\Recipe;
use App\Models\User;
use App\Services\RecipeWorkbenchService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('shows clarified packaging wording on the costing tab', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $ingredient = makeCostingContentCarrierOilIngredient();
    $service = app(RecipeWorkbenchService::class);

    $draftVersion = $service->saveDraft($user, $soapFamily, costingContentDraftPayload($ingredient));
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);

    $this->actingAs($user)
        ->get(route('recipes.edit', $recipe->id))
        ->assertSuccessful()
        ->assertSee('Packaging usage per finished unit')
        ->assertSee('Define how many of each packaging component are used for one finished unit. Batch packaging cost is calculated from this and Units produced.')
        ->assertSee('Packaging item')
        ->assertSee('Components per finished unit')
        ->assertSee('Effective unit price')
        ->assertSee('Cost per finished unit')
        ->assertSee('Batch cost')
        ->assertSee('New packaging item')
        ->assertSee('Save and add to this costing')
        ->assertSee('Save only')
        ->assertSee('No packaging added yet.')
        ->assertSee('Choose a packaging item from your catalog, or create one without leaving this tab.')
        ->assertDontSee('Saved packaging items')
        ->assertDontSee('Packaging in this costing')
        ->assertDontSee('Add custom row')
        ->assertDontSee('Quantity');
});

function makeCostingContentCarrierOilIngredient(): Ingredient
{
    return Ingredient::factory()->create([
        'category' => IngredientCategory::CarrierOil,
        'display_name' => 'Olive Oil',
        'inci_name' => 'OLEA EUROPAEA FRUIT OIL',
        'is_potentially_saponifiable' => true,
        'is_active' => true,
    ]);
}

/**
 * @return array<string, mixed>
 */
function costingContentDraftPayload(Ingredient $ingredient): array
{
    return [
        'name' => 'Packaging Copy Check',
        'oil_unit' => 'g',
        'oil_weight' => 1000,
        'manufacturing_mode' => 'saponify_in_formula',
        'exposure_mode' => 'rinse_off',
        'regulatory_regime' => 'eu',
        'editing_mode' => 'percentage',
        'lye_type' => 'naoh',
        'koh_purity_percentage' => 90,
        'dual_lye_koh_percentage' => 40,
        'water_mode' => 'percent_of_oils',
        'water_value' => 38,
        'superfat' => 5,
        'ifra_product_category_id' => null,
        'phase_items' => [
            'saponified_oils' => [
                [
                    'ingredient_id' => $ingredient->id,
                    'percentage' => 100,
                    'weight' => 1000,
                    'note' => null,
                ],
            ],
            'additives' => [],
            'fragrance' => [],
        ],
    ];
}
