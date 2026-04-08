<?php

use App\IngredientCategory;
use App\Models\IfraProductCategory;
use App\Models\Ingredient;
use App\Models\IngredientSapProfile;
use App\Models\ProductFamily;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use App\Services\RecipeWorkbenchService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('keeps the workbench draft payload intact when building a snapshot', function () {
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);

    $ingredient = recipeWorkbenchCarrierOil();
    IngredientSapProfile::factory()->create([
        'ingredient_id' => $ingredient->id,
        'koh_sap_value' => 0.188,
    ]);

    $draft = recipeWorkbenchDraftPayload($ingredient, [
        'formulaName' => 'Snapshot Contract Formula',
        'oilUnit' => 'oz',
        'oilWeight' => 16,
        'exposureMode' => 'leave_on',
        'selectedIfraProductCategoryId' => IfraProductCategory::factory()->create([
            'is_active' => true,
        ])->id,
    ]);

    $snapshot = app(RecipeWorkbenchService::class)->snapshotFromWorkbenchDraft($draft);

    expect($soapFamily->slug)->toBe('soap')
        ->and($snapshot['draft'])->toEqual($draft)
        ->and($snapshot['calculation'])->not->toBeNull()
        ->and($snapshot['labeling'])->toHaveKeys([
            'basis',
            'default_variant_key',
            'final_label_text',
            'ingredient_rows',
            'declaration_rows',
            'list_variants',
            'warnings',
        ]);
});

it('returns the saved version through the workbench payload and snapshot contract', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $oil = recipeWorkbenchCarrierOil([
        'display_name' => 'Olive Oil',
        'inci_name' => 'OLEA EUROPAEA FRUIT OIL',
        'soap_inci_naoh_name' => 'SODIUM OLIVATE',
        'soap_inci_koh_name' => 'POTASSIUM OLIVATE',
    ]);
    $additive = recipeWorkbenchAdditive([
        'display_name' => 'Green Clay',
        'inci_name' => 'ILLITE',
    ]);
    $ifraCategory = IfraProductCategory::factory()->create([
        'code' => '9',
        'short_name' => 'Soap',
        'is_active' => true,
    ]);

    IngredientSapProfile::factory()->create([
        'ingredient_id' => $oil->id,
        'koh_sap_value' => 0.188,
    ]);

    $service = app(RecipeWorkbenchService::class);
    $draftVersion = $service->saveDraft($user, $soapFamily, recipeWorkbenchPersistencePayload($oil, [
        'name' => 'Contract Draft',
        'oil_unit' => 'oz',
        'oil_weight' => 16,
        'exposure_mode' => 'leave_on',
        'lye_type' => 'dual',
        'dual_lye_koh_percentage' => 55,
        'water_mode' => 'lye_ratio',
        'water_value' => 2.2,
        'superfat' => 7,
        'ifra_product_category_id' => $ifraCategory->id,
        'phase_items' => [
            'additives' => [
                [
                    'ingredient_id' => $additive->id,
                    'percentage' => 2,
                    'weight' => 0.32,
                    'note' => 'Stir in late',
                ],
            ],
        ],
    ]));

    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);

    $service->saveAsNewVersion($user, $soapFamily, recipeWorkbenchPersistencePayload($oil, [
        'name' => 'Published Contract',
        'oil_unit' => 'oz',
        'oil_weight' => 16,
        'exposure_mode' => 'leave_on',
        'lye_type' => 'dual',
        'dual_lye_koh_percentage' => 55,
        'water_mode' => 'lye_ratio',
        'water_value' => 2.2,
        'superfat' => 7,
        'ifra_product_category_id' => $ifraCategory->id,
        'phase_items' => [
            'additives' => [
                [
                    'ingredient_id' => $additive->id,
                    'percentage' => 2,
                    'weight' => 0.32,
                    'note' => 'Stir in late',
                ],
            ],
        ],
    ]), $recipe);

    $publishedVersion = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_draft', false)
        ->latest('version_number')
        ->firstOrFail();

    $payload = $service->versionPayload($recipe, $publishedVersion->id);
    $snapshot = $service->versionSnapshot($recipe, $publishedVersion->id);

    expect($payload)->not->toBeNull()
        ->and($snapshot)->not->toBeNull()
        ->and($snapshot['draft'])->toEqual($payload)
        ->and($payload['recipe'])->toBe([
            'id' => $recipe->id,
            'draft_version_id' => $publishedVersion->id,
            'version_number' => $publishedVersion->version_number,
            'is_draft' => false,
        ])
        ->and($payload['formulaName'])->toBe('Published Contract')
        ->and($payload['oilUnit'])->toBe('oz')
        ->and($payload['oilWeight'])->toBe(16.0)
        ->and($payload['manufacturingMode'])->toBe('saponify_in_formula')
        ->and($payload['exposureMode'])->toBe('leave_on')
        ->and($payload['regulatoryRegime'])->toBe('eu')
        ->and($payload['editMode'])->toBe('percentage')
        ->and($payload['lyeType'])->toBe('dual')
        ->and($payload['dualKohPercentage'])->toBe(55.0)
        ->and($payload['waterMode'])->toBe('lye_ratio')
        ->and($payload['waterValue'])->toBe(2.2)
        ->and($payload['superfat'])->toBe(7.0)
        ->and($payload['selectedIfraProductCategoryId'])->toBe($ifraCategory->id)
        ->and($payload['catalogReview']['needs_review'])->toBeFalse()
        ->and($payload['phaseItems']['saponified_oils'][0]['ingredient_id'])->toBe($oil->id)
        ->and($payload['phaseItems']['additives'][0]['ingredient_id'])->toBe($additive->id)
        ->and($payload['phaseItems']['additives'][0]['note'])->toBe('Stir in late')
        ->and($payload['phaseItems']['fragrance'])->toBe([])
        ->and($snapshot['calculation'])->not->toBeNull()
        ->and($snapshot['labeling'])->toHaveKeys(['default_variant_key', 'final_label_text', 'list_variants']);
});

it('falls back to the latest saved version when no working draft exists', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $oil = recipeWorkbenchCarrierOil();

    IngredientSapProfile::factory()->create([
        'ingredient_id' => $oil->id,
        'koh_sap_value' => 0.188,
    ]);

    $service = app(RecipeWorkbenchService::class);
    $draftVersion = $service->saveDraft($user, $soapFamily, recipeWorkbenchPersistencePayload($oil, [
        'name' => 'Fallback Draft',
    ]));

    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);

    $service->saveAsNewVersion($user, $soapFamily, recipeWorkbenchPersistencePayload($oil, [
        'name' => 'Fallback Published',
    ]), $recipe);

    RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_draft', true)
        ->delete();

    $snapshot = $service->draftSnapshot($recipe);

    expect($snapshot)->not->toBeNull()
        ->and($snapshot['draft']['formulaName'])->toBe('Fallback Published')
        ->and($snapshot['draft']['recipe']['is_draft'])->toBeFalse()
        ->and($snapshot['calculation'])->not->toBeNull();
});

function recipeWorkbenchCarrierOil(array $overrides = []): Ingredient
{
    return Ingredient::factory()->create(array_merge([
        'category' => IngredientCategory::CarrierOil,
        'display_name' => 'Olive Oil',
        'inci_name' => 'OLEA EUROPAEA FRUIT OIL',
        'is_potentially_saponifiable' => true,
        'is_active' => true,
    ], $overrides));
}

function recipeWorkbenchAdditive(array $overrides = []): Ingredient
{
    return Ingredient::factory()->create(array_merge([
        'category' => IngredientCategory::Additive,
        'display_name' => 'Kaolin',
        'inci_name' => 'KAOLIN',
        'is_active' => true,
    ], $overrides));
}

/**
 * @return array<string, mixed>
 */
function recipeWorkbenchDraftPayload(Ingredient $oil, array $overrides = []): array
{
    $oilWeight = (float) ($overrides['oilWeight'] ?? 1000);
    $phaseItemsOverrides = is_array($overrides['phaseItems'] ?? null) ? $overrides['phaseItems'] : [];
    unset($overrides['phaseItems']);

    $payload = array_merge([
        'recipe' => [
            'id' => null,
            'draft_version_id' => null,
            'version_number' => null,
            'is_draft' => true,
        ],
        'formulaName' => 'Workbench Draft',
        'oilUnit' => 'g',
        'oilWeight' => $oilWeight,
        'manufacturingMode' => 'saponify_in_formula',
        'exposureMode' => 'rinse_off',
        'regulatoryRegime' => 'eu',
        'editMode' => 'percentage',
        'lyeType' => 'naoh',
        'kohPurity' => 90,
        'dualKohPercentage' => 40,
        'waterMode' => 'percent_of_oils',
        'waterValue' => 38,
        'superfat' => 5,
        'selectedIfraProductCategoryId' => null,
        'phaseItems' => [
            'saponified_oils' => [
                [
                    'id' => 'draft-oil',
                    'ingredient_id' => $oil->id,
                    'name' => $oil->display_name,
                    'inci_name' => $oil->inci_name,
                    'category' => $oil->category?->value,
                    'soap_inci_naoh_name' => $oil->soap_inci_naoh_name,
                    'soap_inci_koh_name' => $oil->soap_inci_koh_name,
                    'koh_sap_value' => 0.188,
                    'naoh_sap_value' => null,
                    'fatty_acid_profile' => [],
                    'percentage' => 100,
                    'note' => null,
                ],
            ],
            'additives' => [],
            'fragrance' => [],
        ],
        'catalogReview' => [
            'needs_review' => false,
            'reviewed_at' => null,
            'latest_ingredient_change_at' => null,
            'message' => 'Ingredient-linked data matches the last recorded catalog review for this formula version.',
        ],
    ], $overrides);

    foreach (['saponified_oils', 'additives', 'fragrance'] as $phaseKey) {
        if (array_key_exists($phaseKey, $phaseItemsOverrides)) {
            $payload['phaseItems'][$phaseKey] = $phaseItemsOverrides[$phaseKey];
        }
    }

    return $payload;
}

/**
 * @return array<string, mixed>
 */
function recipeWorkbenchPersistencePayload(Ingredient $oil, array $overrides = []): array
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
