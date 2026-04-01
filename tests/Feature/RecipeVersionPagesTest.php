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

it('renders a saved version page with print actions', function () {
    [$user, $recipe, $publishedVersion] = createSavedRecipeVersion();

    $this->actingAs($user)
        ->get(route('recipes.version', ['recipe' => $recipe->id, 'version' => $publishedVersion->id]))
        ->assertSuccessful()
        ->assertSee('Saved version')
        ->assertSee('Print recipe')
        ->assertSee('Print full details')
        ->assertSee('Published Formula');
});

it('recalculates a saved version view when a different oil quantity is requested', function () {
    [$user, $recipe, $publishedVersion] = createSavedRecipeVersion();

    $this->actingAs($user)
        ->get(route('recipes.version', [
            'recipe' => $recipe->id,
            'version' => $publishedVersion->id,
            'oil_weight' => 1500,
        ]))
        ->assertSuccessful()
        ->assertSee('value="1500"', false)
        ->assertSee('Recalculate');
});

it('renders recipe print and full-details print pages for a saved version', function () {
    [$user, $recipe, $publishedVersion] = createSavedRecipeVersion();

    $this->actingAs($user)
        ->get(route('recipes.print.recipe', ['recipe' => $recipe->id, 'version' => $publishedVersion->id]))
        ->assertSuccessful()
        ->assertSee('Recipe print')
        ->assertDontSee('Declaration details');

    $this->actingAs($user)
        ->get(route('recipes.print.details', ['recipe' => $recipe->id, 'version' => $publishedVersion->id]))
        ->assertSuccessful()
        ->assertSee('Full recipe details')
        ->assertSee('Ingredient list preview')
        ->assertSee('Declaration details');
});

it('does not expose saved versions to other users', function () {
    [$owner, $recipe, $publishedVersion] = createSavedRecipeVersion();
    $otherUser = User::factory()->create();

    $this->actingAs($otherUser)
        ->get(route('recipes.version', ['recipe' => $recipe->id, 'version' => $publishedVersion->id]))
        ->assertNotFound();
});

it('can replace the working draft with a chosen saved version', function () {
    [$user, $recipe, $publishedVersion] = createSavedRecipeVersion();

    $this->actingAs($user)
        ->post(route('recipes.use-version-as-draft', ['recipe' => $recipe->id, 'version' => $publishedVersion->id]))
        ->assertRedirect(route('recipes.edit', $recipe->id));

    $draft = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_draft', true)
        ->firstOrFail();

    expect($draft->name)->toBe($publishedVersion->name)
        ->and($draft->version_number)->toBeGreaterThan($publishedVersion->version_number);
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
