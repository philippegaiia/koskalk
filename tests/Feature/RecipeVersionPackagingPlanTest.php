<?php

use App\IngredientCategory;
use App\Models\Ingredient;
use App\Models\IngredientSapProfile;
use App\Models\ProductFamily;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use App\Models\UserPackagingItem;
use App\Services\RecipeWorkbenchService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('stores packaging rows as recipe version structure', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $recipe = Recipe::factory()->create([
        'owner_id' => $user->id,
    ]);
    $version = RecipeVersion::factory()->create([
        'recipe_id' => $recipe->id,
        'owner_id' => $user->id,
    ]);
    $catalogItem = UserPackagingItem::query()->create([
        'user_id' => $user->id,
        'name' => 'Amber jar',
        'unit_cost' => 0.62,
        'currency' => 'EUR',
    ]);

    $row = $version->packagingItems()->create([
        'user_packaging_item_id' => $catalogItem->id,
        'name' => 'Amber jar',
        'components_per_unit' => 1,
        'notes' => '100 ml',
        'position' => 1,
    ]);

    expect($row->recipeVersion->is($version))->toBeTrue()
        ->and($row->packagingItem->is($catalogItem))->toBeTrue()
        ->and((float) $row->components_per_unit)->toBe(1.0)
        ->and($catalogItem->recipeVersionPackagingItems()->first()->is($row))->toBeTrue();
});

it('saves and publishes packaging rows with the recipe version', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $ingredient = packagingPlanIngredient();
    $packagingItem = UserPackagingItem::query()->create([
        'user_id' => $user->id,
        'name' => 'Soap box',
        'unit_cost' => 0.42,
        'currency' => 'EUR',
    ]);

    $payload = packagingPlanDraftPayload($ingredient, 'Boxed soap') + [
        'packaging_items' => [
            [
                'user_packaging_item_id' => $packagingItem->id,
                'name' => 'Soap box',
                'components_per_unit' => 1,
                'notes' => 'Sleeve box',
            ],
        ],
    ];

    $service = app(RecipeWorkbenchService::class);
    $draft = $service->saveDraft($user, $soapFamily, $payload);
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draft->recipe_id);
    $service->saveRecipe($user, $soapFamily, $payload, $recipe);

    $published = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_draft', false)
        ->latest('version_number')
        ->firstOrFail();

    expect($draft->fresh()->packagingItems)->toHaveCount(1)
        ->and($published->packagingItems)->toHaveCount(1)
        ->and($published->packagingItems->first()->name)->toBe('Soap box')
        ->and((float) $published->packagingItems->first()->components_per_unit)->toBe(1.0)
        ->and($published->packagingItems->first()->notes)->toBe('Sleeve box');
});

it('includes packaging rows in the workbench payload', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $ingredient = packagingPlanIngredient();
    $packagingItem = UserPackagingItem::query()->create([
        'user_id' => $user->id,
        'name' => 'Wrap label',
        'unit_cost' => 0.08,
        'currency' => 'EUR',
    ]);

    $draft = app(RecipeWorkbenchService::class)->saveDraft($user, $soapFamily, packagingPlanDraftPayload($ingredient, 'Wrapped soap') + [
        'packaging_items' => [
            [
                'user_packaging_item_id' => $packagingItem->id,
                'name' => 'Wrap label',
                'components_per_unit' => 2,
                'notes' => 'Front and back',
            ],
        ],
    ]);
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draft->recipe_id);

    $payload = app(RecipeWorkbenchService::class)->draftPayload($recipe);

    expect($payload['packagingItems'])->toHaveCount(1)
        ->and($payload['packagingItems'][0]['user_packaging_item_id'])->toBe($packagingItem->id)
        ->and($payload['packagingItems'][0]['name'])->toBe('Wrap label')
        ->and($payload['packagingItems'][0]['components_per_unit'])->toBe(2.0)
        ->and($payload['packagingItems'][0]['notes'])->toBe('Front and back');
});

it('renders packaging as its own workbench tab', function () {
    $user = User::factory()->create();
    ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);

    $this->actingAs($user)
        ->get(route('recipes.create'))
        ->assertSuccessful()
        ->assertSee('Packaging')
        ->assertSee('Packaging plan')
        ->assertSee('Components per unit');
});

it('shows packaging and batch use controls on the official recipe page', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $ingredient = packagingPlanIngredient();
    $packagingItem = UserPackagingItem::query()->create([
        'user_id' => $user->id,
        'name' => 'Soap box',
        'unit_cost' => 0.42,
        'currency' => 'EUR',
    ]);
    $payload = packagingPlanDraftPayload($ingredient, 'Boxed soap') + [
        'packaging_items' => [
            [
                'user_packaging_item_id' => $packagingItem->id,
                'name' => 'Soap box',
                'components_per_unit' => 1,
                'notes' => 'Sleeve box',
            ],
        ],
    ];
    $service = app(RecipeWorkbenchService::class);
    $draft = $service->saveDraft($user, $soapFamily, $payload);
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draft->recipe_id);
    $service->saveRecipe($user, $soapFamily, $payload, $recipe);

    $this->get(route('recipes.saved', ['recipe' => $recipe->id]))
        ->assertSuccessful()
        ->assertSee('Packaging plan')
        ->assertSee('Soap box')
        ->assertSee('1 per unit')
        ->assertSee('Prepare batch')
        ->assertSee('Batch number')
        ->assertSee('Manufacture date')
        ->assertSee('Units produced');
});

function packagingPlanIngredient(): Ingredient
{
    $ingredient = Ingredient::factory()->create([
        'category' => IngredientCategory::CarrierOil,
        'display_name' => 'Olive Oil',
        'inci_name' => 'OLEA EUROPAEA FRUIT OIL',
        'soap_inci_naoh_name' => 'SODIUM OLIVATE',
        'soap_inci_koh_name' => 'POTASSIUM OLIVATE',
        'is_potentially_saponifiable' => true,
        'is_active' => true,
    ]);

    IngredientSapProfile::factory()->create([
        'ingredient_id' => $ingredient->id,
        'koh_sap_value' => 0.188,
    ]);

    return $ingredient;
}

/**
 * @return array<string, mixed>
 */
function packagingPlanDraftPayload(Ingredient $ingredient, string $name): array
{
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
