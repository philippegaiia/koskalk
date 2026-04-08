<?php

use App\IngredientCategory;
use App\Models\Ingredient;
use App\Models\ProductFamily;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\RecipeVersionCosting;
use App\Models\User;
use App\Models\UserIngredientPrice;
use App\Models\UserPackagingItem;
use App\Services\RecipeWorkbenchService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('prefills a costing row from the user ingredient price memory', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $ingredient = makeSharedCarrierOilIngredient();

    UserIngredientPrice::query()->create([
        'user_id' => $user->id,
        'ingredient_id' => $ingredient->id,
        'price_per_kg' => 12.3456,
        'currency' => 'EUR',
        'last_used_at' => now(),
    ]);

    $service = app(RecipeWorkbenchService::class);
    $draftVersion = $service->saveDraft($user, $soapFamily, soapDraftPayload($ingredient));
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);

    $costing = $service->costingPayload($recipe, $user);

    expect($costing['item_prices'])->toHaveCount(1)
        ->and($costing['item_prices'][0]['ingredient_id'])->toBe($ingredient->id)
        ->and($costing['item_prices'][0]['price_per_kg'])->toBe(12.3456);
});

it('saves formula costing separately while updating the user price memory', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $ingredient = makeSharedCarrierOilIngredient();
    $service = app(RecipeWorkbenchService::class);

    $draftVersion = $service->saveDraft($user, $soapFamily, soapDraftPayload($ingredient));
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);

    $service->saveCosting($user, $recipe, [
        'oil_weight_for_costing' => 1000,
        'oil_unit_for_costing' => 'g',
        'units_produced' => 8,
        'currency' => 'EUR',
        'items' => [
            [
                'ingredient_id' => $ingredient->id,
                'phase_key' => 'saponified_oils',
                'position' => 1,
                'price_per_kg' => 8.9123,
            ],
        ],
        'packaging_items' => [],
    ]);

    $costing = RecipeVersionCosting::query()
        ->where('recipe_version_id', $draftVersion->id)
        ->where('user_id', $user->id)
        ->firstOrFail();

    expect($costing->items)->toHaveCount(1)
        ->and((float) $costing->items->first()->price_per_kg)->toBe(8.9123)
        ->and(UserIngredientPrice::query()
            ->where('user_id', $user->id)
            ->where('ingredient_id', $ingredient->id)
            ->value('price_per_kg'))->toBe('8.9123');
});

it('keeps a formula costing stable after the user default ingredient price changes', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $ingredient = makeSharedCarrierOilIngredient();
    $service = app(RecipeWorkbenchService::class);

    $draftVersion = $service->saveDraft($user, $soapFamily, soapDraftPayload($ingredient));
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);

    $service->saveCosting($user, $recipe, [
        'oil_weight_for_costing' => 1000,
        'oil_unit_for_costing' => 'g',
        'units_produced' => 10,
        'currency' => 'EUR',
        'items' => [
            [
                'ingredient_id' => $ingredient->id,
                'phase_key' => 'saponified_oils',
                'position' => 1,
                'price_per_kg' => 6.4,
            ],
        ],
        'packaging_items' => [],
    ]);

    UserIngredientPrice::query()->updateOrCreate(
        [
            'user_id' => $user->id,
            'ingredient_id' => $ingredient->id,
        ],
        [
            'price_per_kg' => 19.99,
            'currency' => 'EUR',
            'last_used_at' => now(),
        ],
    );

    $costing = $service->costingPayload($recipe->fresh(), $user);

    expect($costing['item_prices'][0]['price_per_kg'])->toBe(6.4);
});

it('copies pricing and packaging rows forward when a draft is published into a new version', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $ingredient = makeSharedCarrierOilIngredient();
    $service = app(RecipeWorkbenchService::class);

    $draftVersion = $service->saveDraft($user, $soapFamily, soapDraftPayload($ingredient, name: 'Costed Draft'));
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);
    $packagingItem = UserPackagingItem::query()->create([
        'user_id' => $user->id,
        'name' => 'Bow 100 g',
        'unit_cost' => 0.4400,
        'currency' => 'EUR',
    ]);

    $service->saveCosting($user, $recipe, [
        'oil_weight_for_costing' => 1000,
        'oil_unit_for_costing' => 'g',
        'units_produced' => 12,
        'currency' => 'EUR',
        'items' => [
            [
                'ingredient_id' => $ingredient->id,
                'phase_key' => 'saponified_oils',
                'position' => 1,
                'price_per_kg' => 7.8,
            ],
        ],
        'packaging_items' => [
            [
                'user_packaging_item_id' => $packagingItem->id,
                'name' => $packagingItem->name,
                'unit_cost' => 0.44,
                'quantity' => 12,
            ],
        ],
    ]);

    $newDraftVersion = $service->saveRecipe($user, $soapFamily, soapDraftPayload($ingredient, name: 'Costed Draft'), $recipe);

    $newDraftCosting = RecipeVersionCosting::query()
        ->with(['items', 'packagingItems'])
        ->where('recipe_version_id', $newDraftVersion->id)
        ->where('user_id', $user->id)
        ->firstOrFail();

    expect($newDraftCosting->units_produced)->toBe(12)
        ->and($newDraftCosting->items)->toHaveCount(1)
        ->and((float) $newDraftCosting->items->first()->price_per_kg)->toBe(7.8)
        ->and($newDraftCosting->packagingItems)->toHaveCount(1)
        ->and($newDraftCosting->packagingItems->first()->name)->toBe('Bow 100 g');

    $publishedVersion = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_draft', false)
        ->latest('version_number')
        ->firstOrFail();

    expect(RecipeVersionCosting::query()
        ->where('recipe_version_id', $publishedVersion->id)
        ->where('user_id', $user->id)
        ->exists())->toBeTrue();
});

function makeSharedCarrierOilIngredient(): Ingredient
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
function soapDraftPayload(
    Ingredient $ingredient,
    string $name = 'Recipe',
): array {
    return [
        'name' => $name,
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
