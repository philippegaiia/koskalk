<?php

use App\IngredientCategory;
use App\Models\Ingredient;
use App\Models\ProductFamily;
use App\Models\ProductType;
use App\Models\Recipe;
use App\Models\User;
use App\Services\RecipeWorkbenchService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders the approved costing and packaging language', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $ingredient = makeCostingContentCarrierOilIngredient();
    $service = app(RecipeWorkbenchService::class);

    $draftVersion = $service->save($user, $soapFamily, costingContentDraftPayload($ingredient));
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);

    $this->actingAs($user)
        ->get(route('recipes.edit', $recipe))
        ->assertSuccessful()
        ->assertSeeInOrder([
            'Ingredient costs',
            'Packaging costs',
            'Cost summary',
        ])
        ->assertSee('Packaging plan')
        ->assertSee('Quantity per unit')
        ->assertSee('No packaging added yet.')
        ->assertSee('Create packaging item')
        ->assertSee('Save to library')
        ->assertSee('Save and add')
        ->assertDontSee('Save and add to plan')
        ->assertSee('Costing setup')
        ->assertSee('Oil quantity')
        ->assertSee('Finished units')
        ->assertSee('Your price / kg')
        ->assertSee('Enter finished units')
        ->assertSee('Saponification')
        ->assertSee('Formula additions')
        ->assertSee('Fragrance and aromatics')
        ->assertDontSee('Components per unit')
        ->assertDontSee('New packaging item')
        ->assertDontSee('Business view without cluttering the formula bench')
        ->assertDontSee('Ingredient identity stays shared. The price memory stays private to the current user and can be refreshed later if supplier rates move.')
        ->assertDontSee('Formula rows stay read-only here except for price per kilo, so development and costing each get their own space.')
        ->assertDontSee('Packaging structure comes from the Packaging tab. Costing only updates the effective unit price.')
        ->assertDontSee('Prices stay in Costing so the formula structure stays clear.');
});

it('prompts for finished units on batch-dependent costing summary outputs', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $ingredient = makeCostingContentCarrierOilIngredient();
    $service = app(RecipeWorkbenchService::class);

    $draftVersion = $service->save($user, $soapFamily, costingContentDraftPayload($ingredient));
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);

    $this->actingAs($user)
        ->get(route('recipes.edit', $recipe))
        ->assertSuccessful()
        ->assertSee('Enter finished units')
        ->assertDontSee('Set units produced')
        ->assertDontSee('Unavailable');
});

it('shows the cosmetic batch basis', function () {
    $user = User::factory()->create();
    $cosmeticFamily = ProductFamily::factory()->create([
        'name' => 'Cosmetic',
        'slug' => 'cosmetic',
        'calculation_basis' => 'total_formula',
    ]);
    $productType = ProductType::factory()->create([
        'product_family_id' => $cosmeticFamily->id,
        'name' => 'Cream / lotion',
        'slug' => 'cream-lotion',
    ]);
    $ingredient = Ingredient::factory()->create([
        'display_name' => 'Water',
        'inci_name' => 'AQUA',
        'is_active' => true,
    ]);

    $draftVersion = app(RecipeWorkbenchService::class)->save($user, $cosmeticFamily, [
        'name' => 'Cosmetic Copy Check',
        'product_type_id' => $productType->id,
        'oil_unit' => 'g',
        'oil_weight' => 500,
        'manufacturing_mode' => 'blend_only',
        'exposure_mode' => 'leave_on',
        'regulatory_regime' => 'eu',
        'editing_mode' => 'percentage',
        'ifra_product_category_id' => null,
        'phases' => [
            ['key' => 'hydration', 'name' => 'Hydration & Cool Down'],
        ],
        'phase_items' => [
            'hydration' => [
                [
                    'ingredient_id' => $ingredient->id,
                    'percentage' => 100,
                    'weight' => 500,
                    'note' => null,
                ],
            ],
        ],
    ]);
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);

    $this->actingAs($user)
        ->get(route('recipes.edit', $recipe))
        ->assertSuccessful()
        ->assertSee('Total batch quantity');
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
