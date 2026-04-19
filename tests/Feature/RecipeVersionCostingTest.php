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
use Illuminate\Validation\ValidationException;

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
        'packaging_items' => [
            [
                'user_packaging_item_id' => null,
                'name' => 'Box',
                'unit_cost' => 1.2,
                'components_per_unit' => 2,
            ],
        ],
    ]);

    $costing = RecipeVersionCosting::query()
        ->where('recipe_version_id', $draftVersion->id)
        ->where('user_id', $user->id)
        ->firstOrFail();

    expect($costing->items)->toHaveCount(1)
        ->and((float) $costing->items->first()->price_per_kg)->toBe(8.9123)
        ->and($costing->packagingItems)->toHaveCount(1)
        ->and((float) $costing->packagingItems->first()->quantity)->toBe(2.0)
        ->and(UserIngredientPrice::query()
            ->where('user_id', $user->id)
            ->where('ingredient_id', $ingredient->id)
            ->value('price_per_kg'))->toBe('8.9123');
});

it('keeps the user ingredient price currency when costing updates the remembered amount', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $ingredient = makeSharedCarrierOilIngredient();
    $service = app(RecipeWorkbenchService::class);

    UserIngredientPrice::query()->create([
        'user_id' => $user->id,
        'ingredient_id' => $ingredient->id,
        'price_per_kg' => 4.0000,
        'currency' => 'EUR',
        'last_used_at' => now(),
    ]);

    $draftVersion = $service->saveDraft($user, $soapFamily, soapDraftPayload($ingredient));
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);

    $service->saveCosting($user, $recipe, [
        'oil_weight_for_costing' => 1000,
        'oil_unit_for_costing' => 'g',
        'units_produced' => 8,
        'currency' => 'USD',
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

    expect(UserIngredientPrice::query()
        ->where('user_id', $user->id)
        ->where('ingredient_id', $ingredient->id)
        ->firstOrFail())
        ->price_per_kg->toBe('8.9123')
        ->currency->toBe('EUR');
});

it('saves large ingredient costing prices for high denomination currencies', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $ingredient = makeSharedCarrierOilIngredient();
    $service = app(RecipeWorkbenchService::class);
    $largePricePerKg = 10000000.1234;

    $draftVersion = $service->saveDraft($user, $soapFamily, soapDraftPayload($ingredient));
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);

    $service->saveCosting($user, $recipe, [
        'oil_weight_for_costing' => 1000,
        'oil_unit_for_costing' => 'g',
        'units_produced' => 8,
        'currency' => 'IDR',
        'items' => [
            [
                'ingredient_id' => $ingredient->id,
                'phase_key' => 'saponified_oils',
                'position' => 1,
                'price_per_kg' => $largePricePerKg,
            ],
        ],
        'packaging_items' => [],
    ]);

    $costing = RecipeVersionCosting::query()
        ->where('recipe_version_id', $draftVersion->id)
        ->where('user_id', $user->id)
        ->firstOrFail();

    expect((float) $costing->items->first()->price_per_kg)->toBe($largePricePerKg)
        ->and(UserIngredientPrice::query()
            ->where('user_id', $user->id)
            ->where('ingredient_id', $ingredient->id)
            ->value('price_per_kg'))->toBe('10000000.1234');
});

it('defines costing money columns with room for high denomination currencies', function () {
    $migrationSources = collect([
        database_path('migrations/2026_04_08_055614_create_user_ingredient_prices_table.php'),
        database_path('migrations/2026_04_08_055616_create_user_packaging_items_table.php'),
        database_path('migrations/2026_04_08_055618_create_recipe_version_costing_items_table.php'),
        database_path('migrations/2026_04_08_055619_create_recipe_version_costing_packaging_items_table.php'),
    ])->map(fn (string $path): string => file_get_contents($path) ?: '');

    expect($migrationSources[0])->toContain("decimal('price_per_kg', total: 18, places: 4)")
        ->and($migrationSources[1])->toContain("decimal('unit_cost', total: 18, places: 4)")
        ->and($migrationSources[2])->toContain("decimal('price_per_kg', total: 18, places: 4)")
        ->and($migrationSources[3])->toContain("decimal('unit_cost', total: 18, places: 4)");
});

it('keeps legacy packaging quantity input compatible while storing per-unit usage', function () {
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
        'units_produced' => 12,
        'currency' => 'EUR',
        'items' => [],
        'packaging_items' => [
            [
                'user_packaging_item_id' => null,
                'name' => 'Legacy Wrap',
                'unit_cost' => 0.25,
                'quantity' => 3,
            ],
        ],
    ]);

    $costing = RecipeVersionCosting::query()
        ->where('recipe_version_id', $draftVersion->id)
        ->where('user_id', $user->id)
        ->firstOrFail();

    expect($costing->packagingItems)->toHaveCount(1)
        ->and((float) $costing->packagingItems->first()->quantity)->toBe(3.0);
});

it('updates the saved packaging item amount from costing overrides without changing its currency', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $ingredient = makeSharedCarrierOilIngredient();
    $service = app(RecipeWorkbenchService::class);

    $draftVersion = $service->saveDraft($user, $soapFamily, soapDraftPayload($ingredient));
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);
    $packagingItem = UserPackagingItem::query()->create([
        'user_id' => $user->id,
        'name' => 'Gift Box',
        'unit_cost' => 0.4400,
        'currency' => 'EUR',
    ]);

    $service->saveCosting($user, $recipe, [
        'oil_weight_for_costing' => 1000,
        'oil_unit_for_costing' => 'g',
        'units_produced' => 8,
        'currency' => 'USD',
        'items' => [],
        'packaging_items' => [
            [
                'user_packaging_item_id' => $packagingItem->id,
                'name' => $packagingItem->name,
                'unit_cost' => 0.73,
                'components_per_unit' => 1,
            ],
        ],
    ]);

    expect($packagingItem->fresh())
        ->unit_cost->toBe('0.7300')
        ->currency->toBe('EUR');
});

it('rejects editing another users packaging catalog item from costing', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $packagingItem = UserPackagingItem::query()->create([
        'user_id' => $otherUser->id,
        'name' => 'Other Box',
        'unit_cost' => 0.4400,
        'currency' => 'EUR',
    ]);

    app(RecipeWorkbenchService::class)->savePackagingCatalogItem($user, [
        'id' => $packagingItem->id,
        'name' => 'Hijacked Box',
        'unit_cost' => 0.7300,
        'currency' => 'EUR',
    ]);
})->throws(ValidationException::class);

it('authorizes costing records through their owning user', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
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
        'packaging_items' => [
            [
                'user_packaging_item_id' => null,
                'name' => 'Box',
                'unit_cost' => 1.2,
                'components_per_unit' => 2,
            ],
        ],
    ]);

    $costing = RecipeVersionCosting::query()
        ->with(['items', 'packagingItems'])
        ->where('recipe_version_id', $draftVersion->id)
        ->where('user_id', $user->id)
        ->firstOrFail();
    $ingredientPrice = UserIngredientPrice::query()
        ->where('user_id', $user->id)
        ->where('ingredient_id', $ingredient->id)
        ->firstOrFail();
    $packagingItem = UserPackagingItem::query()->create([
        'user_id' => $user->id,
        'name' => 'Gift Box',
        'unit_cost' => 0.4400,
        'currency' => 'EUR',
    ]);

    expect($user->can('create', UserIngredientPrice::class))->toBeTrue()
        ->and($user->can('update', $ingredientPrice))->toBeTrue()
        ->and($otherUser->can('update', $ingredientPrice))->toBeFalse()
        ->and($user->can('create', UserPackagingItem::class))->toBeTrue()
        ->and($user->can('update', $packagingItem))->toBeTrue()
        ->and($otherUser->can('update', $packagingItem))->toBeFalse()
        ->and($user->can('update', $costing))->toBeTrue()
        ->and($otherUser->can('update', $costing))->toBeFalse()
        ->and($user->can('update', $costing->items->first()))->toBeTrue()
        ->and($otherUser->can('update', $costing->items->first()))->toBeFalse()
        ->and($user->can('update', $costing->packagingItems->first()))->toBeTrue()
        ->and($otherUser->can('update', $costing->packagingItems->first()))->toBeFalse()
        ->and($user->can('forceDelete', $costing))->toBeFalse();
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
                'components_per_unit' => 2,
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
        ->and($newDraftCosting->packagingItems->first()->name)->toBe('Bow 100 g')
        ->and((float) $newDraftCosting->packagingItems->first()->quantity)->toBe(2.0);

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
