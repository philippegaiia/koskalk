<?php

use App\IngredientCategory;
use App\Models\Ingredient;
use App\Models\IngredientSapProfile;
use App\Models\ProductFamily;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use App\Services\RecipeWorkbenchService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders the current saved formula page with print actions', function () {
    [$user, $recipe, $publishedVersion] = createSavedRecipeVersion();

    $this->actingAs($user)
        ->get(route('recipes.saved', ['recipe' => $recipe->id]))
        ->assertSuccessful()
        ->assertSee('Saved formula')
        ->assertSee('v'.$publishedVersion->version_number)
        ->assertSee('Edit in draft')
        ->assertSee('Duplicate')
        ->assertDontSee('Recovery snapshots')
        ->assertSee('Print recipe')
        ->assertSee('Print full details')
        ->assertSee('Published Formula');
});

it('shows recovery snapshots section when there are multiple saved versions', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create(['slug' => 'soap', 'name' => 'Soap']);
    $ingredient = makeSavedRecipeIngredient();
    $service = app(RecipeWorkbenchService::class);

    $draftVersion = $service->saveDraft($user, $soapFamily, soapVersionDraftPayload($ingredient, 'Formula A'));
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);

    $service->saveRecipe($user, $soapFamily, soapVersionDraftPayload($ingredient, 'Formula A'), $recipe);
    $service->saveRecipe($user, $soapFamily, soapVersionDraftPayload($ingredient, 'Formula B'), $recipe);

    $this->actingAs($user)
        ->get(route('recipes.saved', ['recipe' => $recipe->id]))
        ->assertSuccessful()
        ->assertSee('Recovery snapshots');
});

it('recalculates the saved formula view when a different oil quantity is requested', function () {
    [$user, $recipe, $publishedVersion] = createSavedRecipeVersion();

    $this->actingAs($user)
        ->get(route('recipes.saved', [
            'recipe' => $recipe->id,
            'oil_weight' => 1500,
        ]))
        ->assertSuccessful()
        ->assertSee('value="1500"', false)
        ->assertSee('Recalculate');
});

it('renders recipe print and full-details print pages for the current saved formula', function () {
    [$user, $recipe, $publishedVersion] = createSavedRecipeVersion();

    $this->actingAs($user)
        ->get(route('recipes.print.recipe', ['recipe' => $recipe->id]))
        ->assertSuccessful()
        ->assertSee('Recipe print')
        ->assertDontSee('Declaration details');

    $this->actingAs($user)
        ->get(route('recipes.print.details', ['recipe' => $recipe->id]))
        ->assertSuccessful()
        ->assertSee('Full recipe details')
        ->assertSee('Ingredient list preview')
        ->assertSee('Declaration details');
});

it('does not expose the saved formula to other users', function () {
    [$owner, $recipe, $publishedVersion] = createSavedRecipeVersion();
    $otherUser = User::factory()->create();

    $this->actingAs($otherUser)
        ->get(route('recipes.saved', ['recipe' => $recipe->id]))
        ->assertNotFound();
});

it('keeps the legacy saved-version url working by showing the current saved formula', function () {
    [$user, $recipe, $publishedVersion] = createSavedRecipeVersion();

    $this->actingAs($user)
        ->get(route('recipes.version', ['recipe' => $recipe->id, 'version' => $publishedVersion->id]))
        ->assertSuccessful()
        ->assertSee('Saved formula')
        ->assertSee('Published Formula');
});

it('duplicates a recipe into a new draft recipe', function () {
    [$user, $recipe, $publishedVersion] = createSavedRecipeVersion();

    $this->actingAs($user)
        ->post(route('recipes.duplicate', ['recipe' => $recipe->id]))
        ->assertRedirect();

    expect(Recipe::withoutGlobalScopes()->count())->toBe(2)
        ->and(RecipeVersion::withoutGlobalScopes()->where('is_draft', true)->count())->toBe(2)
        ->and(Recipe::withoutGlobalScopes()->latest('id')->firstOrFail()->name)->toBe('Copy of Published Formula');
});

it('can refresh the draft from the current saved formula page', function () {
    [$user, $recipe, $publishedVersion] = createSavedRecipeVersion();

    $draft = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_draft', true)
        ->firstOrFail();

    $this->actingAs($user)
        ->post(route('recipes.saved.edit-in-draft', ['recipe' => $recipe->id]))
        ->assertRedirect(route('recipes.edit', $recipe->id));

    $draft->refresh();

    expect($draft->name)->toBe('Published Formula');
});

it('forbids refreshing the draft from the saved formula when signed out', function () {
    [$user, $recipe, $publishedVersion] = createSavedRecipeVersion();

    $this->post(route('recipes.saved.edit-in-draft', ['recipe' => $recipe->id]))
        ->assertForbidden();
});

it('asks for confirmation before replacing a changed draft with the saved formula', function () {
    [$user, $recipe, $publishedVersion] = createSavedRecipeVersion();

    $draft = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_draft', true)
        ->firstOrFail();

    $draft->update([
        'name' => 'Experimental Draft',
    ]);

    $this->actingAs($user)
        ->post(route('recipes.saved.edit-in-draft', ['recipe' => $recipe->id]))
        ->assertRedirect(route('recipes.saved', $recipe->id))
        ->assertSessionHas('draftReplaceConfirmation');

    $draft->refresh();

    expect($draft->name)->toBe('Experimental Draft');

    $this->actingAs($user)
        ->post(route('recipes.saved.edit-in-draft', ['recipe' => $recipe->id]), [
            'confirm_replace_draft' => '1',
        ])
        ->assertRedirect(route('recipes.edit', $recipe->id));

    $draft->refresh();

    expect($draft->name)->toBe('Published Formula');
});

it('can restore an older saved snapshot as the current saved formula', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $ingredient = makeSavedRecipeIngredient();
    $service = app(RecipeWorkbenchService::class);

    $draftVersion = $service->saveDraft(
        $user,
        $soapFamily,
        soapVersionDraftPayload($ingredient, 'Formula A'),
    );

    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);

    $service->saveRecipe($user, $soapFamily, soapVersionDraftPayload($ingredient, 'Formula A'), $recipe);
    $service->saveRecipe($user, $soapFamily, soapVersionDraftPayload($ingredient, 'Formula B'), $recipe);

    $olderSavedVersion = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_draft', false)
        ->where('name', 'Formula A')
        ->latest('version_number')
        ->firstOrFail();

    $this->actingAs($user)
        ->post(route('recipes.saved.restore', ['recipe' => $recipe->id, 'version' => $olderSavedVersion->id]))
        ->assertRedirect(route('recipes.saved', $recipe->id));

    $latestSavedVersion = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_draft', false)
        ->latest('version_number')
        ->firstOrFail();

    expect($latestSavedVersion->name)->toBe('Formula A');
});

it('forbids restoring a saved snapshot when signed out', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $ingredient = makeSavedRecipeIngredient();
    $service = app(RecipeWorkbenchService::class);

    $draftVersion = $service->saveDraft(
        $user,
        $soapFamily,
        soapVersionDraftPayload($ingredient, 'Formula A'),
    );

    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);

    $service->saveRecipe($user, $soapFamily, soapVersionDraftPayload($ingredient, 'Formula A'), $recipe);

    $savedVersion = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_draft', false)
        ->latest('version_number')
        ->firstOrFail();

    $this->post(route('recipes.saved.restore', ['recipe' => $recipe->id, 'version' => $savedVersion->id]))
        ->assertForbidden();
});

it('preserves the current draft when restoring an older saved snapshot', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $ingredient = makeSavedRecipeIngredient();
    $service = app(RecipeWorkbenchService::class);

    $draftVersion = $service->saveDraft(
        $user,
        $soapFamily,
        soapVersionDraftPayload($ingredient, 'Formula A'),
    );

    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);

    $service->saveRecipe($user, $soapFamily, soapVersionDraftPayload($ingredient, 'Formula A'), $recipe);
    $service->saveRecipe($user, $soapFamily, soapVersionDraftPayload($ingredient, 'Formula B'), $recipe);

    $currentDraft = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_draft', true)
        ->firstOrFail();

    $currentDraft->update([
        'name' => 'Experimental Draft',
    ]);

    $olderSavedVersion = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_draft', false)
        ->where('name', 'Formula A')
        ->latest('version_number')
        ->firstOrFail();

    $this->actingAs($user)
        ->post(route('recipes.saved.restore', ['recipe' => $recipe->id, 'version' => $olderSavedVersion->id]))
        ->assertRedirect(route('recipes.saved', $recipe->id));

    $currentDraft->refresh();

    expect($currentDraft->name)->toBe('Experimental Draft');

    $latestSavedVersion = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_draft', false)
        ->latest('version_number')
        ->firstOrFail();

    expect($latestSavedVersion->name)->toBe('Formula A');
});

it('asks for confirmation before replacing the draft with an older recovery snapshot', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $ingredient = makeSavedRecipeIngredient();
    $service = app(RecipeWorkbenchService::class);

    $draftVersion = $service->saveDraft(
        $user,
        $soapFamily,
        soapVersionDraftPayload($ingredient, 'Formula A'),
    );

    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);

    $service->saveRecipe($user, $soapFamily, soapVersionDraftPayload($ingredient, 'Formula A'), $recipe);
    $service->saveRecipe($user, $soapFamily, soapVersionDraftPayload($ingredient, 'Formula B'), $recipe);

    $currentDraft = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_draft', true)
        ->firstOrFail();

    $currentDraft->update([
        'name' => 'Experimental Draft',
    ]);

    $olderSavedVersion = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_draft', false)
        ->where('name', 'Formula A')
        ->latest('version_number')
        ->firstOrFail();

    $this->actingAs($user)
        ->post(route('recipes.use-version-as-draft', ['recipe' => $recipe->id, 'version' => $olderSavedVersion->id]))
        ->assertRedirect(route('recipes.saved', $recipe->id))
        ->assertSessionHas('draftReplaceConfirmation');

    $currentDraft->refresh();

    expect($currentDraft->name)->toBe('Experimental Draft');

    $this->actingAs($user)
        ->post(route('recipes.use-version-as-draft', ['recipe' => $recipe->id, 'version' => $olderSavedVersion->id]), [
            'confirm_replace_draft' => '1',
        ])
        ->assertRedirect(route('recipes.edit', $recipe->id));

    $currentDraft->refresh();

    expect($currentDraft->name)->toBe('Formula A');
});

it('forbids replacing the draft with a saved version when signed out', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $ingredient = makeSavedRecipeIngredient();
    $service = app(RecipeWorkbenchService::class);

    $draftVersion = $service->saveDraft(
        $user,
        $soapFamily,
        soapVersionDraftPayload($ingredient, 'Formula A'),
    );

    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);

    $service->saveRecipe($user, $soapFamily, soapVersionDraftPayload($ingredient, 'Formula A'), $recipe);
    $service->saveRecipe($user, $soapFamily, soapVersionDraftPayload($ingredient, 'Formula B'), $recipe);

    $olderSavedVersion = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_draft', false)
        ->where('name', 'Formula A')
        ->latest('version_number')
        ->firstOrFail();

    $this->post(route('recipes.use-version-as-draft', ['recipe' => $recipe->id, 'version' => $olderSavedVersion->id]))
        ->assertForbidden();
});

/**
 * @return array{0: User, 1: Recipe, 2: RecipeVersion}
 */
function createSavedRecipeVersion(): array
{
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $ingredient = makeSavedRecipeIngredient();

    $service = app(RecipeWorkbenchService::class);
    $draftVersion = $service->saveDraft(
        $user,
        $soapFamily,
        soapVersionDraftPayload($ingredient, 'Workbench Draft'),
    );

    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);

    $service->saveAsNewVersion(
        $user,
        $soapFamily,
        soapVersionDraftPayload($ingredient, 'Published Formula'),
        $recipe,
    );

    $publishedVersion = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_draft', false)
        ->latest('version_number')
        ->firstOrFail();

    return [$user, $recipe, $publishedVersion];
}

function makeSavedRecipeIngredient(): Ingredient
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
function soapVersionDraftPayload(Ingredient $ingredient, string $name): array
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
