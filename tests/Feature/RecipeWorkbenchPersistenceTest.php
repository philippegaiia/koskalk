<?php

use App\IngredientCategory;
use App\Livewire\Dashboard\RecipeWorkbench;
use App\Models\FattyAcid;
use App\Models\Ingredient;
use App\Models\IngredientSapProfile;
use App\Models\IngredientVersion;
use App\Models\IngredientVersionFattyAcid;
use App\Models\ProductFamily;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use App\Services\RecipeWorkbenchService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('syncs the parent recipe name when a saved draft is renamed', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $ingredientVersion = makeCarrierOilIngredientVersion();
    $service = app(RecipeWorkbenchService::class);

    $draftVersion = $service->saveDraft(
        $user,
        $soapFamily,
        soapDraftPayload($ingredientVersion, name: 'Recipe A'),
    );
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);

    $service->saveDraft(
        $user,
        $soapFamily,
        soapDraftPayload($ingredientVersion, name: 'Recipe B'),
        $recipe,
    );

    $recipe = $recipe->fresh();

    expect($recipe->name)->toBe('Recipe B')
        ->and(RecipeVersion::withoutGlobalScopes()
            ->where('recipe_id', $draftVersion->recipe_id)
            ->where('is_draft', true)
            ->count())->toBe(1);
});

it('returns a structured error instead of throwing when oil weight is invalid', function () {
    $user = User::factory()->create();
    ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $ingredientVersion = makeCarrierOilIngredientVersion();

    $this->actingAs($user);

    $component = app(RecipeWorkbench::class);
    $component->mount();

    $result = $component->saveDraft(
        soapDraftPayload($ingredientVersion, oilWeight: 0),
        app(RecipeWorkbenchService::class),
    );

    expect($result['ok'])->toBeFalse()
        ->and($result['errors'])->toHaveKey('oil_weight')
        ->and($result['errors']['oil_weight'][0])->toContain('oil weight');
});

it('can still save a draft from a mounted component after the auth session is gone', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $ingredientVersion = makeCarrierOilIngredientVersion();

    $this->actingAs($user);

    $component = app(RecipeWorkbench::class);
    $component->mount();

    auth()->logout();

    $result = $component->saveDraft(
        soapDraftPayload($ingredientVersion, name: 'Fallback Draft'),
        app(RecipeWorkbenchService::class),
    );

    expect($result['ok'])->toBeTrue()
        ->and($result['draft']['recipe']['id'])->not->toBeNull();

    $recipe = Recipe::withoutGlobalScopes()->findOrFail($result['draft']['recipe']['id']);

    expect($recipe->owner_id)->toBe($user->id)
        ->and($recipe->name)->toBe('Fallback Draft')
        ->and($soapFamily->id)->toBe($recipe->product_family_id);
});

it('returns backend soap calculation preview data for the workbench', function () {
    ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);

    $ingredientVersion = makeCarrierOilIngredientVersion();

    IngredientSapProfile::factory()->create([
        'ingredient_version_id' => $ingredientVersion->id,
        'koh_sap_value' => 0.188,
    ]);

    $oleic = FattyAcid::factory()->create([
        'key' => 'oleic',
        'name' => 'Oleic',
    ]);
    $palmitic = FattyAcid::factory()->create([
        'key' => 'palmitic',
        'name' => 'Palmitic',
    ]);

    IngredientVersionFattyAcid::factory()->create([
        'ingredient_version_id' => $ingredientVersion->id,
        'fatty_acid_id' => $oleic->id,
        'percentage' => 71,
    ]);
    IngredientVersionFattyAcid::factory()->create([
        'ingredient_version_id' => $ingredientVersion->id,
        'fatty_acid_id' => $palmitic->id,
        'percentage' => 13,
    ]);

    $component = app(RecipeWorkbench::class);
    $component->mount();

    $result = $component->previewCalculation(
        soapDraftPayload($ingredientVersion, oilWeight: 1000),
        app(RecipeWorkbenchService::class),
    );

    expect($result['ok'])->toBeTrue()
        ->and($result['calculation'])->not->toBeNull()
        ->and($result['calculation']['properties']['fatty_acid_profile']['oleic'])->toBe(71.0)
        ->and($result['calculation']['properties']['qualities'])->toHaveKey('unmolding_firmness');
});

function makeCarrierOilIngredientVersion(): IngredientVersion
{
    $ingredient = Ingredient::factory()->create([
        'category' => IngredientCategory::CarrierOil,
        'is_potentially_saponifiable' => true,
        'is_active' => true,
    ]);

    return IngredientVersion::factory()->create([
        'ingredient_id' => $ingredient->id,
        'is_current' => true,
        'is_active' => true,
    ]);
}

/**
 * @return array<string, mixed>
 */
function soapDraftPayload(IngredientVersion $ingredientVersion, string $name = 'Recipe', float $oilWeight = 1000): array
{
    return [
        'name' => $name,
        'oil_unit' => 'g',
        'oil_weight' => $oilWeight,
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
                    'ingredient_id' => $ingredientVersion->ingredient_id,
                    'ingredient_version_id' => $ingredientVersion->id,
                    'percentage' => 100,
                    'weight' => $oilWeight,
                    'note' => null,
                ],
            ],
            'additives' => [],
            'fragrance' => [],
        ],
    ];
}
