<?php

use App\IngredientCategory;
use App\Livewire\Dashboard\RecipeWorkbench;
use App\Models\Ingredient;
use App\Models\IngredientSapProfile;
use App\Models\ProductFamily;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('shows the real soap workbench to anonymous calculator visitors', function () {
    ProductFamily::factory()->create(['slug' => 'soap']);

    publicCalculatorIngredient('Olive Oil', IngredientCategory::CarrierOil);
    publicCalculatorIngredient('Lavender Essential Oil', IngredientCategory::EssentialOil);
    publicCalculatorIngredient('French Green Clay', IngredientCategory::Clay);

    $this->get(route('calculator'))
        ->assertSuccessful()
        ->assertSeeText('Soap lye calculator')
        ->assertSeeText('Formula')
        ->assertSeeText('Reaction core')
        ->assertSeeText('Additives and aromatics')
        ->assertSeeText('Fatty acid profile')
        ->assertSeeText('Output')
        ->assertSeeText('Save with free account')
        ->assertSeeText('Add private ingredients')
        ->assertSeeText('Unlock packaging and costing')
        ->assertSee('recipeWorkbench(', false)
        ->assertSee('Olive Oil')
        ->assertSee('Lavender Essential Oil')
        ->assertSee('French Green Clay')
        ->assertDontSeeText('Packaging plan')
        ->assertDontSeeText('Costing settings');
});

it('keeps anonymous calculator formulas from being saved until registration', function () {
    ProductFamily::factory()->create(['slug' => 'soap']);
    $oil = publicCalculatorIngredient('Olive Oil', IngredientCategory::CarrierOil, kohSap: 0.188);

    Livewire::test(RecipeWorkbench::class, ['productFamilySlug' => 'soap'])
        ->call('publish', publicCalculatorDraftPayload($oil))
        ->assertReturned(fn (array $return): bool => ($return['ok'] ?? null) === false
            && str_contains($return['message'] ?? '', 'signed in'));
});

function publicCalculatorIngredient(string $name, IngredientCategory $category, ?float $kohSap = null): Ingredient
{
    $ingredient = Ingredient::factory()->create([
        'display_name' => $name,
        'category' => $category,
        'is_potentially_saponifiable' => $category === IngredientCategory::CarrierOil,
        'is_active' => true,
    ]);

    if ($kohSap !== null) {
        IngredientSapProfile::factory()->create([
            'ingredient_id' => $ingredient->id,
            'koh_sap_value' => $kohSap,
        ]);
    }

    return $ingredient;
}

/**
 * @return array<string, mixed>
 */
function publicCalculatorDraftPayload(Ingredient $ingredient): array
{
    return [
        'name' => 'Guest Formula',
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
