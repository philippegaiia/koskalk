<?php

use App\IngredientCategory;
use App\Livewire\Dashboard\RecipeWorkbench;
use App\Models\Ingredient;
use App\Models\IngredientSapProfile;
use App\Models\ProductFamily;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\RecipePhase;
use App\Models\RecipeVersion;
use App\Models\User;
use App\Services\RecipeWorkbenchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('allows an owner to delete a recipe via the delete route', function (): void {
    [$user, $recipe, $draft, $publishedVersion] = createRecipeWithDraftAndPublishedVersion();

    $phaseIds = RecipePhase::withoutGlobalScopes()
        ->whereIn('recipe_version_id', [$draft->id, $publishedVersion->id])
        ->pluck('id');
    $itemIds = RecipeItem::withoutGlobalScopes()
        ->whereIn('recipe_version_id', [$draft->id, $publishedVersion->id])
        ->pluck('id');

    actingAs($user)
        ->delete(route('recipes.destroy', $recipe->id), [
            'confirm_name' => $recipe->name,
        ])
        ->assertRedirect(route('recipes.index'))
        ->assertSessionHas('status', 'Recipe deleted.');

    expect(Recipe::withoutGlobalScopes()->find($recipe->id))->toBeNull()
        ->and(RecipeVersion::withoutGlobalScopes()->find($draft->id))->toBeNull()
        ->and(RecipeVersion::withoutGlobalScopes()->find($publishedVersion->id))->toBeNull()
        ->and(RecipePhase::withoutGlobalScopes()->whereIn('id', $phaseIds)->exists())->toBeFalse()
        ->and(RecipeItem::withoutGlobalScopes()->whereIn('id', $itemIds)->exists())->toBeFalse();
});

it('rejects recipe deletion with the wrong confirmation name', function (): void {
    [$user, $recipe] = createRecipeWithDraftAndPublishedVersion();

    actingAs($user)
        ->delete(route('recipes.destroy', $recipe->id), [
            'confirm_name' => 'Wrong Name',
        ])
        ->assertForbidden();

    expect(Recipe::withoutGlobalScopes()->find($recipe->id))->not->toBeNull();
});

it('rejects recipe deletion by an unauthorized user', function (): void {
    [$user, $recipe] = createRecipeWithDraftAndPublishedVersion();
    $otherUser = User::factory()->create();

    actingAs($otherUser)
        ->delete(route('recipes.destroy', $recipe->id), [
            'confirm_name' => $recipe->name,
        ])
        ->assertForbidden();

    expect(Recipe::withoutGlobalScopes()->find($recipe->id))->not->toBeNull();
});

it('deletes recipe media files when a recipe is permanently deleted', function (): void {
    Storage::fake('public');

    config([
        'media.disk' => 'public',
        'media.visibility' => 'public',
    ]);

    [$user, $recipe] = createRecipeWithDraftAndPublishedVersion();

    $recipe->forceFill([
        'featured_image_path' => 'recipes/featured-images/featured.webp',
        'description' => '<p><img data-id="recipes/rich-content/presentation.webp" src="/storage/recipes/rich-content/presentation.webp"></p>',
        'manufacturing_instructions' => '<p><img data-id="recipes/rich-content/instructions.webp" src="/storage/recipes/rich-content/instructions.webp"></p>',
    ])->save();

    Storage::disk('public')->put('recipes/featured-images/featured.webp', 'featured');
    Storage::disk('public')->put('recipes/rich-content/presentation.webp', 'presentation');
    Storage::disk('public')->put('recipes/rich-content/instructions.webp', 'instructions');

    actingAs($user)
        ->delete(route('recipes.destroy', $recipe->id), [
            'confirm_name' => $recipe->name,
        ])
        ->assertRedirect(route('recipes.index'));

    expect(Storage::disk('public')->exists('recipes/featured-images/featured.webp'))->toBeFalse()
        ->and(Storage::disk('public')->exists('recipes/rich-content/presentation.webp'))->toBeFalse()
        ->and(Storage::disk('public')->exists('recipes/rich-content/instructions.webp'))->toBeFalse();
});

it('allows an owner to delete a published version via the delete route', function (): void {
    [$user, $recipe, $draft, $publishedVersion] = createRecipeWithDraftAndPublishedVersion();
    $recipe->update(['name' => 'Recipe Shell']);

    $phaseIds = RecipePhase::withoutGlobalScopes()
        ->where('recipe_version_id', $publishedVersion->id)
        ->pluck('id');
    $itemIds = RecipeItem::withoutGlobalScopes()
        ->where('recipe_version_id', $publishedVersion->id)
        ->pluck('id');

    actingAs($user)
        ->delete(route('recipes.versions.destroy', ['recipe' => $recipe->id, 'version' => $publishedVersion->id]), [
            'confirm_name' => $publishedVersion->name,
        ])
        ->assertRedirect(route('recipes.index'))
        ->assertSessionHas('status', 'Last published version deleted. Recipe has no published versions.');

    expect(Recipe::withoutGlobalScopes()->find($recipe->id))->not->toBeNull()
        ->and(RecipeVersion::withoutGlobalScopes()->find($draft->id))->not->toBeNull()
        ->and(RecipeVersion::withoutGlobalScopes()->find($publishedVersion->id))->toBeNull()
        ->and(RecipePhase::withoutGlobalScopes()->whereIn('id', $phaseIds)->exists())->toBeFalse()
        ->and(RecipeItem::withoutGlobalScopes()->whereIn('id', $itemIds)->exists())->toBeFalse();
});

it('rejects published version deletion when the recipe name is provided instead of the version name', function (): void {
    [$user, $recipe, , $publishedVersion] = createRecipeWithDraftAndPublishedVersion();

    $recipe->update(['name' => 'Recipe Shell']);

    actingAs($user)
        ->delete(route('recipes.versions.destroy', ['recipe' => $recipe->id, 'version' => $publishedVersion->id]), [
            'confirm_name' => $recipe->name,
        ])
        ->assertForbidden();

    expect(RecipeVersion::withoutGlobalScopes()->find($publishedVersion->id))->not->toBeNull();
});

it('shows the standard version deleted message when other published versions remain', function (): void {
    [$user, $recipe] = createRecipeWithTwoPublishedVersions();
    $publishedVersions = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_draft', false)
        ->orderBy('version_number')
        ->get();

    actingAs($user)
        ->delete(route('recipes.versions.destroy', ['recipe' => $recipe->id, 'version' => $publishedVersions->first()->id]), [
            'confirm_name' => $publishedVersions->first()->name,
        ])
        ->assertRedirect(route('recipes.index'))
        ->assertSessionHas('status', 'Version deleted.');

    expect(RecipeVersion::withoutGlobalScopes()->find($publishedVersions->first()->id))->toBeNull()
        ->and(
            RecipeVersion::withoutGlobalScopes()
                ->where('recipe_id', $recipe->id)
                ->where('is_draft', false)
                ->count()
        )->toBe(1);
});

it('rejects version deletion when the version belongs to a different recipe', function (): void {
    [$user, $recipe] = createRecipeWithDraftAndPublishedVersion();
    [, $otherRecipe, , $otherPublishedVersion] = createRecipeWithDraftAndPublishedVersion($user);

    actingAs($user)
        ->delete(route('recipes.versions.destroy', ['recipe' => $recipe->id, 'version' => $otherPublishedVersion->id]), [
            'confirm_name' => $otherPublishedVersion->name,
        ])
        ->assertNotFound();

    expect(RecipeVersion::withoutGlobalScopes()->find($otherPublishedVersion->id))->not->toBeNull();
});

it('deletes a workbench draft and redirects to the recipes index', function (): void {
    [$user, $recipe, $draft] = createRecipeWithDraftOnly();

    $this->actingAs($user);

    Livewire::test(RecipeWorkbench::class, ['recipe' => $recipe])
        ->call('deleteVersion', $draft->id)
        ->assertRedirect(route('recipes.index'));

    expect(RecipeVersion::withoutGlobalScopes()->find($draft->id))->toBeNull();
});

it('rejects deleting a published workbench version when confirmation does not match', function (): void {
    [$user, $recipe, , $publishedVersion] = createRecipeWithDraftAndPublishedVersion();

    $this->actingAs($user);

    Livewire::test(RecipeWorkbench::class, ['recipe' => $recipe])
        ->call('deleteVersion', $publishedVersion->id, 'Wrong Name')
        ->assertHasErrors(['confirmName']);

    expect(RecipeVersion::withoutGlobalScopes()->find($publishedVersion->id))->not->toBeNull();
});

it('dispatches version-deleted after deleting a published workbench version', function (): void {
    [$user, $recipe, , $publishedVersion] = createRecipeWithDraftAndPublishedVersion();
    $expectedVersionName = $publishedVersion->name;

    $this->actingAs($user);

    Livewire::test(RecipeWorkbench::class, ['recipe' => $recipe])
        ->call('deleteVersion', $publishedVersion->id, $publishedVersion->name)
        ->assertDispatched('version-deleted', function (string $name, array $params) use ($expectedVersionName): bool {
            return $name === 'version-deleted'
                && ($params['message'] ?? null) === 'Last published version deleted. Recipe has no published versions.'
                && ($params['versionName'] ?? null) === $expectedVersionName
                && is_array($params['versionOptions'] ?? null)
                && $params['versionOptions'] === [];
        });

    expect(RecipeVersion::withoutGlobalScopes()->find($publishedVersion->id))->toBeNull();
});

/**
 * @return array{0: User, 1: Recipe, 2: RecipeVersion}
 */
function createRecipeWithDraftOnly(): array
{
    $user = User::factory()->create();
    $soapFamily = makeDeletionSoapFamily();
    $ingredient = makeDeletionCarrierOilIngredient();

    IngredientSapProfile::factory()->create([
        'ingredient_id' => $ingredient->id,
        'koh_sap_value' => 0.188,
    ]);

    $draft = app(RecipeWorkbenchService::class)->saveDraft(
        $user,
        $soapFamily,
        deletionSoapDraftPayload($ingredient, 'Workbench Draft'),
    );

    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draft->recipe_id);

    return [$user, $recipe, $draft];
}

/**
 * @return array{0: User, 1: Recipe, 2: RecipeVersion, 3: RecipeVersion}
 */
function createRecipeWithDraftAndPublishedVersion(?User $user = null): array
{
    $user ??= User::factory()->create();
    $soapFamily = makeDeletionSoapFamily();
    $ingredient = makeDeletionCarrierOilIngredient();

    IngredientSapProfile::factory()->create([
        'ingredient_id' => $ingredient->id,
        'koh_sap_value' => 0.188,
    ]);

    $service = app(RecipeWorkbenchService::class);

    $initialDraft = $service->saveDraft(
        $user,
        $soapFamily,
        deletionSoapDraftPayload($ingredient, 'Workbench Draft'),
    );

    $recipe = Recipe::withoutGlobalScopes()->findOrFail($initialDraft->recipe_id);

    $draft = $service->saveAsNewVersion(
        $user,
        $soapFamily,
        deletionSoapDraftPayload($ingredient, 'Published Formula'),
        $recipe,
    );

    $publishedVersion = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_draft', false)
        ->latest('version_number')
        ->firstOrFail();

    return [$user, $recipe, $draft, $publishedVersion];
}

/**
 * @return array{0: User, 1: Recipe}
 */
function createRecipeWithTwoPublishedVersions(): array
{
    [$user, $recipe] = createRecipeWithDraftAndPublishedVersion();
    $soapFamily = makeDeletionSoapFamily();
    $ingredient = makeDeletionCarrierOilIngredient();

    IngredientSapProfile::factory()->create([
        'ingredient_id' => $ingredient->id,
        'koh_sap_value' => 0.188,
    ]);

    app(RecipeWorkbenchService::class)->saveAsNewVersion(
        $user,
        $soapFamily,
        deletionSoapDraftPayload($ingredient, 'Published Formula Two'),
        $recipe,
    );

    return [$user, $recipe];
}

function makeDeletionSoapFamily(): ProductFamily
{
    return ProductFamily::query()->firstOrCreate(
        ['slug' => 'soap'],
        [
            'name' => 'Soap',
            'calculation_basis' => 'initial_oils',
            'is_active' => true,
            'description' => 'Soap formulas',
        ],
    );
}

function makeDeletionCarrierOilIngredient(): Ingredient
{
    return Ingredient::factory()->create([
        'category' => IngredientCategory::CarrierOil,
        'display_name' => 'Olive Oil',
        'inci_name' => 'OLEA EUROPAEA FRUIT OIL',
        'soap_inci_naoh_name' => 'SODIUM OLIVATE',
        'soap_inci_koh_name' => 'POTASSIUM OLIVATE',
        'is_potentially_saponifiable' => true,
        'is_active' => true,
    ]);
}

/**
 * @return array<string, mixed>
 */
function deletionSoapDraftPayload(Ingredient $ingredient, string $name): array
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
