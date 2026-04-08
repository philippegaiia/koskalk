<?php

use App\IngredientCategory;
use App\Models\Ingredient;
use App\Models\ProductFamily;
use App\Models\Recipe;
use App\Models\User;
use App\Services\RecipeWorkbenchService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders packaging below ingredient costing with simplified wording', function () {
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
        ->assertSeeInOrder([
            'Ingredient costing',
            'Packaging',
            'Cost summary',
        ])
        ->assertSee('Add reusable packaging items used for one finished unit.')
        ->assertSee('Packaging item')
        ->assertSee('Add packaging item')
        ->assertSee('New packaging item')
        ->assertSee('Components per unit')
        ->assertSee('Unit price')
        ->assertSee('Cost per unit')
        ->assertSee('Batch cost')
        ->assertSee('Save and add')
        ->assertSee('Save only')
        ->assertSee('No packaging added yet.')
        ->assertSee('Add a reusable packaging item to include boxes, labels, stickers, and other unit-level packaging in this costing.')
        ->assertDontSee('Packaging usage per finished unit')
        ->assertDontSee('Quantity');
});

it('shows units-produced fallback on batch-dependent costing summary outputs', function () {
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
        ->assertSee('Set units produced')
        ->assertDontSee('Unavailable');
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
