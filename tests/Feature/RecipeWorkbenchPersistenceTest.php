<?php

use App\IngredientCategory;
use App\Livewire\Dashboard\RecipeWorkbench;
use App\Models\FattyAcid;
use App\Models\IfraProductCategory;
use App\Models\Ingredient;
use App\Models\IngredientFattyAcid;
use App\Models\IngredientSapProfile;
use App\Models\ProductFamily;
use App\Models\ProductFamilyIfraCategory;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use App\Services\RecipeWorkbenchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('syncs the parent recipe name when a saved draft is renamed', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $ingredient = makeCarrierOilIngredient();
    $service = app(RecipeWorkbenchService::class);

    $draftVersion = $service->saveDraft(
        $user,
        $soapFamily,
        soapDraftPayload($ingredient, name: 'Recipe A'),
    );
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);

    $service->saveDraft(
        $user,
        $soapFamily,
        soapDraftPayload($ingredient, name: 'Recipe B'),
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
    $ingredient = makeCarrierOilIngredient();

    $this->actingAs($user);

    $component = app(RecipeWorkbench::class);
    $component->mount();

    $result = $component->saveDraft(
        soapDraftPayload($ingredient, oilWeight: 0),
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
    $ingredient = makeCarrierOilIngredient();

    $this->actingAs($user);

    $component = app(RecipeWorkbench::class);
    $component->mount();

    auth()->logout();

    $result = $component->saveDraft(
        soapDraftPayload($ingredient, name: 'Fallback Draft'),
        app(RecipeWorkbenchService::class),
    );

    expect($result['ok'])->toBeTrue()
        ->and($result['snapshot']['draft']['recipe']['id'])->not->toBeNull();

    $recipe = Recipe::withoutGlobalScopes()->findOrFail($result['snapshot']['draft']['recipe']['id']);

    expect($recipe->owner_id)->toBe($user->id)
        ->and($recipe->name)->toBe('Fallback Draft')
        ->and($soapFamily->id)->toBe($recipe->product_family_id);
});

it('returns backend soap calculation preview data for the workbench', function () {
    ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);

    $ingredient = makeCarrierOilIngredient();

    IngredientSapProfile::factory()->create([
        'ingredient_id' => $ingredient->id,
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

    IngredientFattyAcid::factory()->create([
        'ingredient_id' => $ingredient->id,
        'fatty_acid_id' => $oleic->id,
        'percentage' => 71,
    ]);
    IngredientFattyAcid::factory()->create([
        'ingredient_id' => $ingredient->id,
        'fatty_acid_id' => $palmitic->id,
        'percentage' => 13,
    ]);

    $component = app(RecipeWorkbench::class);
    $component->mount();

    $result = $component->previewCalculation(
        soapDraftPayload($ingredient, oilWeight: 1000),
        app(RecipeWorkbenchService::class),
    );

    expect($result['ok'])->toBeTrue()
        ->and($result['calculation'])->not->toBeNull()
        ->and($result['calculation']['properties']['fatty_acid_profile']['oleic'])->toBe(71.0)
        ->and($result['calculation']['properties']['qualities'])->toHaveKey('unmolding_firmness');
});

it('stores formula context on recipe versions and returns it in the draft payload', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $ingredient = makeCarrierOilIngredient();
    $service = app(RecipeWorkbenchService::class);

    $draftVersion = $service->saveDraft(
        $user,
        $soapFamily,
        soapDraftPayload($ingredient, exposureMode: 'leave_on'),
    );

    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);
    $draft = $service->draftPayload($recipe);
    $freshDraftVersion = $draftVersion->fresh();

    expect($freshDraftVersion)->not->toBeNull()
        ->and($freshDraftVersion?->manufacturing_mode)->toBe('saponify_in_formula')
        ->and($freshDraftVersion?->exposure_mode)->toBe('leave_on')
        ->and($freshDraftVersion?->regulatory_regime)->toBe('eu')
        ->and($freshDraftVersion?->catalog_reviewed_at)->not->toBeNull()
        ->and($draft['manufacturingMode'])->toBe('saponify_in_formula')
        ->and($draft['exposureMode'])->toBe('leave_on')
        ->and($draft['regulatoryRegime'])->toBe('eu')
        ->and($draft['catalogReview']['needs_review'])->toBeFalse();
});

it('flags a saved formula for review when linked ingredient data changes', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $ingredient = makeCarrierOilIngredient();
    $service = app(RecipeWorkbenchService::class);

    $draftVersion = $service->saveDraft(
        $user,
        $soapFamily,
        soapDraftPayload($ingredient),
    );

    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);

    expect($service->draftPayload($recipe)['catalogReview']['needs_review'])->toBeFalse();

    $this->travel(1)->seconds();

    $ingredient->update([
        'display_name' => 'Updated Oil Name',
    ]);

    $updatedDraft = $service->draftPayload($recipe);

    expect($updatedDraft['catalogReview']['needs_review'])->toBeTrue()
        ->and($updatedDraft['catalogReview']['message'])->toContain('Recheck INCI and compliance');
});

it('loads a saved version for comparison', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $ingredient = makeCarrierOilIngredient();

    IngredientSapProfile::factory()->create([
        'ingredient_id' => $ingredient->id,
        'koh_sap_value' => 0.188,
    ]);

    $service = app(RecipeWorkbenchService::class);
    $draftVersion = $service->saveDraft(
        $user,
        $soapFamily,
        soapDraftPayload($ingredient, name: 'Baseline Draft'),
    );

    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);

    $savedDraft = $service->saveAsNewVersion(
        $user,
        $soapFamily,
        soapDraftPayload($ingredient, name: 'Published Formula'),
        $recipe,
    );

    $publishedVersion = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_draft', false)
        ->latest('version_number')
        ->firstOrFail();

    $this->actingAs($user);

    $component = app(RecipeWorkbench::class);
    $component->recipeId = $savedDraft->recipe_id;
    $component->mount($recipe);

    $result = $component->comparisonVersion(
        $publishedVersion->id,
        app(RecipeWorkbenchService::class),
    );

    expect($result['ok'])->toBeTrue()
        ->and($result['snapshot']['draft']['formulaName'])->toBe('Published Formula')
        ->and($result['snapshot']['calculation'])->not->toBeNull();
});

it('saves recipe content through the standalone filament form', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $recipe = Recipe::factory()->create([
        'product_family_id' => $soapFamily->id,
        'owner_id' => $user->id,
    ]);

    $this->actingAs($user);

    Livewire::test(RecipeWorkbench::class, ['recipe' => $recipe])
        ->set('data.description', '<p>Blend the base gently, then pour into the mould.</p>')
        ->set('data.featured_image_path', ['recipes/featured-images/soap.jpg'])
        ->call('saveRecipeContent')
        ->assertSet('recipeContentStatus', 'success');

    expect($recipe->fresh())
        ->description->toContain('Blend the base gently')
        ->featured_image_path->toBe('recipes/featured-images/soap.jpg');
});

it('keeps comparison snapshots aligned with the version payload and backend calculation', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $ingredient = makeCarrierOilIngredient();

    IngredientSapProfile::factory()->create([
        'ingredient_id' => $ingredient->id,
        'koh_sap_value' => 0.188,
    ]);

    $service = app(RecipeWorkbenchService::class);
    $draftVersion = $service->saveDraft(
        $user,
        $soapFamily,
        soapDraftPayload($ingredient, name: 'Comparison Draft'),
    );

    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);

    $service->saveAsNewVersion(
        $user,
        $soapFamily,
        soapDraftPayload($ingredient, name: 'Comparison Baseline'),
        $recipe,
    );

    $publishedVersion = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_draft', false)
        ->latest('version_number')
        ->firstOrFail();

    $expectedSnapshot = $service->versionSnapshot($recipe, $publishedVersion->id);

    $this->actingAs($user);

    $component = app(RecipeWorkbench::class);
    $component->recipeId = $recipe->id;
    $component->mount($recipe);

    $result = $component->comparisonVersion(
        $publishedVersion->id,
        $service,
    );

    expect($result['ok'])->toBeTrue()
        ->and($result['snapshot'])->toEqual($expectedSnapshot);
});

it('loads saved versions with the same snapshot contract used for comparison', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $ingredient = makeCarrierOilIngredient();

    IngredientSapProfile::factory()->create([
        'ingredient_id' => $ingredient->id,
        'koh_sap_value' => 0.188,
    ]);

    $service = app(RecipeWorkbenchService::class);
    $draftVersion = $service->saveDraft(
        $user,
        $soapFamily,
        soapDraftPayload($ingredient, name: 'Workbench Draft'),
    );

    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);

    $service->saveAsNewVersion(
        $user,
        $soapFamily,
        soapDraftPayload($ingredient, name: 'Opened Baseline'),
        $recipe,
    );

    $publishedVersion = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_draft', false)
        ->latest('version_number')
        ->firstOrFail();

    $expectedSnapshot = $service->versionSnapshot($recipe, $publishedVersion->id);

    $this->actingAs($user);

    $component = app(RecipeWorkbench::class);
    $component->recipeId = $recipe->id;
    $component->mount($recipe);

    $result = $component->loadVersion(
        $publishedVersion->id,
        $service,
    );

    expect($result['ok'])->toBeTrue()
        ->and($result['snapshot'])->toEqual($expectedSnapshot)
        ->and($result['message'])->toContain('Saved version loaded');
});

it('returns no soap calculation preview for blend-only formulas', function () {
    ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);

    $ingredient = makeCarrierOilIngredient();

    IngredientSapProfile::factory()->create([
        'ingredient_id' => $ingredient->id,
        'koh_sap_value' => 0.188,
    ]);

    $component = app(RecipeWorkbench::class);
    $component->mount();

    $draft = soapDraftPayload($ingredient, oilWeight: 1000);
    $draft['manufacturing_mode'] = 'blend_only';

    $result = $component->previewCalculation(
        $draft,
        app(RecipeWorkbenchService::class),
    );

    expect($result['ok'])->toBeTrue()
        ->and($result['calculation'])->toBeNull();
});

it('exposes workbench phase options for saponifiable oils, additive-only oils, and aromatics', function () {
    ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);

    $trustedCarrierIngredient = Ingredient::factory()->create([
        'category' => IngredientCategory::CarrierOil,
        'is_potentially_saponifiable' => true,
        'is_active' => true,
    ]);

    $customCarrierIngredient = Ingredient::factory()->create([
        'category' => IngredientCategory::CarrierOil,
        'display_name' => 'Custom Fig Oil',
        'is_potentially_saponifiable' => false,
        'is_active' => true,
    ]);

    $fragranceIngredient = Ingredient::factory()->create([
        'category' => IngredientCategory::FragranceOil,
        'display_name' => 'Rose Accord',
        'is_active' => true,
    ]);

    $component = app(RecipeWorkbench::class);
    $component->mount();

    $workbench = $component->render(app(RecipeWorkbenchService::class))->getData()['workbench'];
    $ingredients = collect($workbench['ingredients'])->keyBy('id');

    expect($ingredients)->toHaveKeys([
        $trustedCarrierIngredient->id,
        $customCarrierIngredient->id,
        $fragranceIngredient->id,
    ])
        ->and($ingredients[$trustedCarrierIngredient->id]['available_phases'])->toBe(['saponified_oils', 'additives'])
        ->and($ingredients[$trustedCarrierIngredient->id]['default_phase'])->toBe('saponified_oils')
        ->and($ingredients[$customCarrierIngredient->id]['available_phases'])->toBe(['additives'])
        ->and($ingredients[$customCarrierIngredient->id]['default_phase'])->toBe('additives')
        ->and($ingredients[$fragranceIngredient->id]['available_phases'])->toBe(['fragrance'])
        ->and($ingredients[$fragranceIngredient->id]['needs_compliance'])->toBeTrue();
});

it('orders ifra categories naturally and exposes cat 9 as the default soap context', function () {
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);

    $category10A = IfraProductCategory::factory()->create([
        'code' => '10A',
        'short_name' => 'Household hand contact',
        'is_active' => true,
    ]);
    $category9 = IfraProductCategory::factory()->create([
        'code' => '9',
        'short_name' => 'Soap / shower gel / rinse-off',
        'is_active' => true,
    ]);
    $category2 = IfraProductCategory::factory()->create([
        'code' => '2',
        'short_name' => 'Deodorants / axillae',
        'is_active' => true,
    ]);

    ProductFamilyIfraCategory::factory()->create([
        'product_family_id' => $soapFamily->id,
        'ifra_product_category_id' => $category10A->id,
        'is_default' => false,
        'sort_order' => 3,
    ]);
    ProductFamilyIfraCategory::factory()->create([
        'product_family_id' => $soapFamily->id,
        'ifra_product_category_id' => $category9->id,
        'is_default' => true,
        'sort_order' => 2,
    ]);
    ProductFamilyIfraCategory::factory()->create([
        'product_family_id' => $soapFamily->id,
        'ifra_product_category_id' => $category2->id,
        'is_default' => false,
        'sort_order' => 1,
    ]);

    $component = app(RecipeWorkbench::class);
    $component->mount();

    $workbench = $component->render(app(RecipeWorkbenchService::class))->getData()['workbench'];

    expect(collect($workbench['ifraProductCategories'])->pluck('code')->all())
        ->toBe(['2', '9', '10A'])
        ->and($workbench['defaultIfraProductCategoryId'])->toBe($category9->id);
});

function makeCarrierOilIngredient(): Ingredient
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
    float $oilWeight = 1000,
    string $exposureMode = 'rinse_off',
): array {
    return [
        'name' => $name,
        'oil_unit' => 'g',
        'oil_weight' => $oilWeight,
        'manufacturing_mode' => 'saponify_in_formula',
        'exposure_mode' => $exposureMode,
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
                    'weight' => $oilWeight,
                    'note' => null,
                ],
            ],
            'additives' => [],
            'fragrance' => [],
        ],
    ];
}
