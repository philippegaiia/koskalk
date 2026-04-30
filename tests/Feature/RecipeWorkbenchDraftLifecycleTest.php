<?php

use App\IngredientCategory;
use App\Livewire\Dashboard\RecipeWorkbench;
use App\Models\Ingredient;
use App\Models\IngredientSapProfile;
use App\Models\ProductFamily;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use App\Services\RecipeContentUpdater;
use App\Services\RecipeWorkbenchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;

uses(RefreshDatabase::class);

it('publishes the current draft and opens a fresh draft when saving as a new version', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $oil = recipeWorkbenchLifecycleOil();

    IngredientSapProfile::factory()->create([
        'ingredient_id' => $oil->id,
        'koh_sap_value' => 0.188,
    ]);

    $service = app(RecipeWorkbenchService::class);
    $draftVersion = $service->saveDraft($user, $soapFamily, recipeWorkbenchLifecyclePayload($oil, [
        'name' => 'Working Draft',
    ]));

    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);

    $newDraft = $service->saveAsNewVersion($user, $soapFamily, recipeWorkbenchLifecyclePayload($oil, [
        'name' => 'Published Formula',
        'water_value' => 33,
    ]), $recipe);

    $publishedVersion = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_draft', false)
        ->firstOrFail();

    expect($publishedVersion->name)->toBe('Published Formula')
        ->and($publishedVersion->saved_at)->not->toBeNull()
        ->and($publishedVersion->version_number)->toBe(1)
        ->and($newDraft->is_draft)->toBeTrue()
        ->and($newDraft->saved_at)->toBeNull()
        ->and($newDraft->version_number)->toBe(2)
        ->and($newDraft->name)->toBe('Published Formula')
        ->and($recipe->fresh()->name)->toBe('Published Formula');
});

it('can still save through a mounted component after the auth session is gone', function () {
    $user = User::factory()->create();
    ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $oil = recipeWorkbenchLifecycleOil();

    $this->actingAs($user);

    $component = app(RecipeWorkbench::class);
    $component->mount();

    auth()->logout();

    $result = $component->saveDraft(
        recipeWorkbenchLifecyclePayload($oil, [
            'name' => 'Fallback Draft',
        ]),
        app(RecipeWorkbenchService::class),
        app(RecipeContentUpdater::class),
    );

    expect($result['ok'])->toBeTrue()
        ->and($result['snapshot']['draft']['recipe']['id'])->not->toBeNull();

    $recipe = Recipe::withoutGlobalScopes()->findOrFail($result['snapshot']['draft']['recipe']['id']);

    expect($recipe->owner_id)->toBe($user->id)
        ->and($recipe->name)->toBe('Fallback Draft');
});

it('returns a validation response instead of crashing when saving NaOH soap with negative superfat', function () {
    $user = User::factory()->create();
    ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $oil = recipeWorkbenchLifecycleOil();

    IngredientSapProfile::factory()->create([
        'ingredient_id' => $oil->id,
        'koh_sap_value' => 0.188,
    ]);

    $this->actingAs($user);

    $component = app(RecipeWorkbench::class);
    $component->mount();

    $result = $component->saveDraft(
        recipeWorkbenchLifecyclePayload($oil, [
            'name' => 'Invalid NaOH Negative Superfat',
            'lye_type' => 'naoh',
            'superfat' => -2,
        ]),
        app(RecipeWorkbenchService::class),
        app(RecipeContentUpdater::class),
    );

    expect($result['ok'])->toBeFalse()
        ->and($result['message'])->toBe('Negative superfat is only supported for liquid or high-KOH soap workflows.')
        ->and(Recipe::withoutGlobalScopes()->where('name', 'Invalid NaOH Negative Superfat')->exists())->toBeFalse();
});

it('replaces the working draft with the selected saved version using the same workbench payload shape', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $oil = recipeWorkbenchLifecycleOil();

    IngredientSapProfile::factory()->create([
        'ingredient_id' => $oil->id,
        'koh_sap_value' => 0.188,
    ]);

    $service = app(RecipeWorkbenchService::class);
    $draftVersion = $service->saveDraft($user, $soapFamily, recipeWorkbenchLifecyclePayload($oil, [
        'name' => 'Original Draft',
        'water_value' => 31,
        'superfat' => 4,
    ]));

    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);

    $service->saveAsNewVersion($user, $soapFamily, recipeWorkbenchLifecyclePayload($oil, [
        'name' => 'Published Baseline',
        'exposure_mode' => 'leave_on',
        'water_mode' => 'lye_ratio',
        'water_value' => 2.1,
        'superfat' => 7,
    ]), $recipe);

    $publishedVersion = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_draft', false)
        ->latest('version_number')
        ->firstOrFail();

    $expectedPayload = $service->versionPayload($recipe, $publishedVersion->id);

    $service->useVersionAsDraft($user, $recipe, $publishedVersion->id);

    $actualPayload = $service->draftPayload($recipe->fresh());

    expect($actualPayload)->not->toBeNull()
        ->and(recipeWorkbenchComparableDraftPayload($actualPayload))
        ->toEqual(recipeWorkbenchComparableDraftPayload($expectedPayload))
        ->and($actualPayload['recipe']['is_draft'])->toBeTrue()
        ->and($actualPayload['recipe']['version_number'])->toBeGreaterThan($publishedVersion->version_number)
        ->and($actualPayload['catalogReview']['needs_review'])->toBeFalse();
});

it('publishes after restoring a recovery snapshot without reusing the recovery version number', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $oil = recipeWorkbenchLifecycleOil();

    IngredientSapProfile::factory()->create([
        'ingredient_id' => $oil->id,
        'koh_sap_value' => 0.188,
    ]);

    $service = app(RecipeWorkbenchService::class);
    $draftVersion = $service->saveDraft($user, $soapFamily, recipeWorkbenchLifecyclePayload($oil, [
        'name' => 'Formula A',
    ]));

    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);

    $service->saveRecipe($user, $soapFamily, recipeWorkbenchLifecyclePayload($oil, [
        'name' => 'Formula A',
    ]), $recipe);
    $service->saveRecipe($user, $soapFamily, recipeWorkbenchLifecyclePayload($oil, [
        'name' => 'Formula B',
    ]), $recipe);

    $olderSavedVersion = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_draft', false)
        ->where('name', 'Formula A')
        ->latest('version_number')
        ->firstOrFail();

    $restoredVersion = $service->restoreSavedFormula($user, $recipe, $olderSavedVersion->id);
    $newDraft = $service->saveRecipe($user, $soapFamily, recipeWorkbenchLifecyclePayload($oil, [
        'name' => 'Published After Restore',
    ]), $recipe);

    expect($newDraft->is_draft)->toBeTrue()
        ->and($newDraft->version_number)->toBeGreaterThan($restoredVersion->version_number);
});

function recipeWorkbenchLifecycleOil(array $overrides = []): Ingredient
{
    return Ingredient::factory()->create(array_merge([
        'category' => IngredientCategory::CarrierOil,
        'display_name' => 'Olive Oil',
        'inci_name' => 'OLEA EUROPAEA FRUIT OIL',
        'soap_inci_naoh_name' => 'SODIUM OLIVATE',
        'soap_inci_koh_name' => 'POTASSIUM OLIVATE',
        'is_potentially_saponifiable' => true,
        'is_active' => true,
    ], $overrides));
}

/**
 * @return array<string, mixed>
 */
function recipeWorkbenchLifecyclePayload(Ingredient $oil, array $overrides = []): array
{
    $oilWeight = (float) ($overrides['oil_weight'] ?? 1000);
    $phaseItemsOverrides = is_array($overrides['phase_items'] ?? null) ? $overrides['phase_items'] : [];
    unset($overrides['phase_items']);

    $payload = array_merge([
        'name' => 'Recipe',
        'oil_unit' => 'g',
        'oil_weight' => $oilWeight,
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
                    'ingredient_id' => $oil->id,
                    'percentage' => 100,
                    'weight' => $oilWeight,
                    'note' => null,
                ],
            ],
            'additives' => [],
            'fragrance' => [],
        ],
    ], $overrides);

    foreach (['saponified_oils', 'additives', 'fragrance'] as $phaseKey) {
        if (array_key_exists($phaseKey, $phaseItemsOverrides)) {
            $payload['phase_items'][$phaseKey] = $phaseItemsOverrides[$phaseKey];
        }
    }

    return $payload;
}

/**
 * @param  array<string, mixed>  $payload
 * @return array<string, mixed>
 */
function recipeWorkbenchComparableDraftPayload(array $payload): array
{
    $comparable = Arr::except($payload, ['recipe', 'catalogReview']);

    foreach (['saponified_oils', 'additives', 'fragrance'] as $phaseKey) {
        $comparable['phaseItems'][$phaseKey] = collect($comparable['phaseItems'][$phaseKey] ?? [])
            ->map(fn (array $row): array => Arr::except($row, ['id']))
            ->values()
            ->all();
    }

    return $comparable;
}
