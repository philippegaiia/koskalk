<?php

use App\IngredientCategory;
use App\Livewire\Dashboard\IngredientsIndex;
use App\Models\Ingredient;
use App\Models\IngredientComponent;
use App\Models\Plan;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\RecipeVersion;
use App\Models\RecipeVersionCosting;
use App\Models\RecipeVersionCostingItem;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\OwnerType;
use App\Visibility;
use App\WorkspaceMemberRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders the public ingredients index with only the current users private ingredients', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $ownedIngredient = Ingredient::factory()->create([
        'category' => IngredientCategory::Additive,
        'display_name' => 'My Glycerin',
        'inci_name' => 'GLYCERIN',
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
        'source_file' => 'user',
        'source_key' => 'USR-OWNED',
    ]);

    $hiddenIngredient = Ingredient::factory()->create([
        'category' => IngredientCategory::Additive,
        'display_name' => 'Hidden Glycerin',
        'inci_name' => 'GLYCERIN',
        'owner_type' => OwnerType::User,
        'owner_id' => $otherUser->id,
        'visibility' => Visibility::Private,
        'source_file' => 'user',
        'source_key' => 'USR-HIDDEN',
    ]);

    $this->actingAs($user)
        ->get(route('ingredients.index'))
        ->assertSuccessful()
        ->assertSee('My Glycerin')
        ->assertDontSee('Hidden Glycerin');
});

it('lets the signed-in user search their ingredient catalog table', function () {
    $user = User::factory()->create();

    $glycerin = Ingredient::factory()->create([
        'category' => IngredientCategory::Additive,
        'display_name' => 'My Glycerin',
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
        'source_file' => 'user',
        'source_key' => 'USR-GLYCERIN',
    ]);

    $clay = Ingredient::factory()->create([
        'category' => IngredientCategory::Clay,
        'display_name' => 'White Clay',
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
        'source_file' => 'user',
        'source_key' => 'USR-CLAY',
    ]);

    $this->actingAs($user);

    Livewire::test(IngredientsIndex::class)
        ->assertSee($glycerin->display_name)
        ->assertSee($clay->display_name)
        ->set('search', 'Glycerin')
        ->assertSee($glycerin->display_name)
        ->assertDontSee($clay->display_name);
});

it('renders an accessible not applicable action for platform ingredients', function () {
    $user = User::factory()->create();

    Ingredient::factory()->create([
        'display_name' => 'Platform Glycerin',
        'owner_type' => null,
        'owner_id' => null,
    ]);

    $this->actingAs($user);

    Livewire::test(IngredientsIndex::class)
        ->assertSeeHtml('aria-label="Not applicable"')
        ->assertDontSee('Reference');
});

it('allows deleting an unused personal ingredient from the catalog table', function () {
    Storage::fake('public');

    config([
        'media.disk' => 'public',
        'media.visibility' => 'public',
    ]);

    $user = User::factory()->create();

    $ingredient = Ingredient::factory()->create([
        'category' => IngredientCategory::Additive,
        'display_name' => 'Disposable Ingredient',
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
        'source_file' => 'user',
        'source_key' => 'USR-DELETE',
        'featured_image_path' => 'ingredients/featured-images/delete-me.webp',
        'icon_image_path' => 'ingredients/icons/delete-me.webp',
    ]);

    Storage::disk('public')->put('ingredients/featured-images/delete-me.webp', 'image');
    Storage::disk('public')->put('ingredients/icons/delete-me.webp', 'icon');

    $this->actingAs($user);

    Livewire::test(IngredientsIndex::class)
        ->call('confirmDelete', $ingredient->id)
        ->assertSet('pendingDeleteId', $ingredient->id)
        ->assertSee('This removes the ingredient from your private catalog.')
        ->call('deleteIngredient')
        ->assertSet('pendingDeleteId', null)
        ->assertSee('Disposable Ingredient was deleted.');

    expect(Ingredient::query()->find($ingredient->id))->toBeNull()
        ->and(Storage::disk('public')->exists('ingredients/featured-images/delete-me.webp'))->toBeFalse()
        ->and(Storage::disk('public')->exists('ingredients/icons/delete-me.webp'))->toBeFalse();
});

it('uses the complex removal dialog for a composite only dependency', function () {
    $user = User::factory()->create();
    $source = catalogPrivateIngredient($user, IngredientCategory::Additive, 'Nested Extract');
    $replacement = catalogPrivateIngredient($user, IngredientCategory::Additive, 'Replacement Extract');
    $parent = catalogPrivateIngredient($user, IngredientCategory::Additive, 'Private Blend');
    IngredientComponent::factory()->create([
        'ingredient_id' => $parent->id,
        'component_ingredient_id' => $source->id,
    ]);

    $this->actingAs($user);

    Livewire::test(IngredientsIndex::class)
        ->assertDontSee('Used in 1 formula')
        ->call('confirmDelete', $source->id)
        ->assertSee('Used in 1 composite ingredient.')
        ->assertDontSee('Also used in 1 composite ingredient.')
        ->assertSee('Replace everywhere and delete')
        ->assertDontSee('This removes the ingredient from your private catalog.')
        ->set('replacementIngredientId', $replacement->id)
        ->call('replaceEverywhereAndDelete')
        ->assertHasNoErrors();

    expect($source->fresh())->toBeNull()
        ->and(IngredientComponent::query()
            ->whereBelongsTo($parent)
            ->where('component_ingredient_id', $replacement->id)
            ->exists())->toBeTrue();
});

it('shows and blocks a visible composite dependency that cannot be edited', function () {
    $user = User::factory()->create();
    $source = catalogPrivateIngredient($user, IngredientCategory::Additive, 'Protected Extract');
    $replacement = catalogPrivateIngredient($user, IngredientCategory::Additive, 'Replacement Extract');
    $platformParent = Ingredient::factory()->create([
        'category' => IngredientCategory::Additive,
        'display_name' => 'Platform Protected Blend',
        'owner_type' => null,
        'owner_id' => null,
        'visibility' => Visibility::Public,
    ]);
    IngredientComponent::factory()->create([
        'ingredient_id' => $platformParent->id,
        'component_ingredient_id' => $source->id,
    ]);

    $this->actingAs($user);

    $component = Livewire::test(IngredientsIndex::class)
        ->call('confirmDelete', $source->id)
        ->assertSee('Platform Protected Blend')
        ->assertSee('Edit the affected composite ingredients manually')
        ->assertDontSee('This removes the ingredient from your private catalog.');

    expect(ingredientButtonIsDisabled($component->html(), null, 'replaceEverywhereAndDelete'))->toBeTrue()
        ->and(ingredientButtonIsDisabled($component->html(), null, 'removeEverywhereAndDelete'))->toBeTrue();

    $component
        ->set('replacementIngredientId', $replacement->id)
        ->call('replaceEverywhereAndDelete')
        ->assertHasErrors(['ingredient'])
        ->call('removeEverywhereAndDelete')
        ->assertHasErrors(['ingredient']);

    expect($source->fresh())->not->toBeNull();
});

it('discloses formula usage reached through nested composite ingredients', function () {
    $user = User::factory()->create();
    $source = catalogPrivateIngredient($user, IngredientCategory::Additive, 'Nested Source');
    $directParent = catalogPrivateIngredient($user, IngredientCategory::Additive, 'Direct Parent');
    $nestedParent = catalogPrivateIngredient($user, IngredientCategory::Additive, 'Nested Parent');
    IngredientComponent::factory()->create([
        'ingredient_id' => $directParent->id,
        'component_ingredient_id' => $source->id,
    ]);
    IngredientComponent::factory()->create([
        'ingredient_id' => $nestedParent->id,
        'component_ingredient_id' => $directParent->id,
    ]);
    [$recipe] = catalogFormulaUsage($user, $nestedParent, 'Nested Composite Formula');

    $this->actingAs($user);

    Livewire::test(IngredientsIndex::class)
        ->assertSee('Used in 1 formula')
        ->call('toggleUsage', $source->id)
        ->assertSee($recipe->name)
        ->call('confirmDelete', $source->id)
        ->assertSee('Used in 1 formula')
        ->assertSee('Also used in 2 composite ingredients.')
        ->assertSee('Replace everywhere and delete');
});

it('returns to the first catalog page after deleting the only item on page two', function () {
    $user = User::factory()->create();

    foreach (range(1, 25) as $number) {
        catalogPrivateIngredient(
            $user,
            IngredientCategory::Additive,
            sprintf('Catalog Ingredient %02d', $number),
        );
    }

    $lastIngredient = catalogPrivateIngredient($user, IngredientCategory::Additive, 'ZZ Remove Me');

    $this->actingAs($user);

    Livewire::test(IngredientsIndex::class)
        ->call('setOwnershipFilter', 'mine')
        ->call('setPage', 2)
        ->assertSet('paginators.page', 2)
        ->assertSee('ZZ Remove Me')
        ->call('confirmDelete', $lastIngredient->id)
        ->call('deleteIngredient')
        ->assertSet('paginators.page', 1)
        ->assertSee('Catalog Ingredient 01')
        ->assertDontSee('No ingredients match');

    expect($lastIngredient->fresh())->toBeNull();
});

it('opens a used ingredient decision dialog with compatible replacements', function () {
    $user = User::factory()->create();
    $source = catalogPrivateIngredient($user, IngredientCategory::EssentialOil, 'Lavender Essential Oil');
    $compatible = Ingredient::factory()->create([
        'category' => IngredientCategory::FragranceOil,
        'display_name' => 'Lavender Fragrance',
    ]);
    $incompatible = Ingredient::factory()->create([
        'category' => IngredientCategory::Clay,
        'display_name' => 'White Clay',
    ]);
    catalogFormulaUsage($user, $source, 'Evening Soap');

    $this->actingAs($user);

    $component = Livewire::test(IngredientsIndex::class)
        ->assertSee('Used in 1 formula')
        ->assertSeeHtml('id="ingredient-delete-trigger-'.$source->id.'"')
        ->assertSeeHtml('aria-label="Manage removal of Lavender Essential Oil"')
        ->call('confirmDelete', $source->id)
        ->assertSet('pendingDeleteId', $source->id)
        ->assertSee('Manage Lavender Essential Oil')
        ->assertSee('Used in 1 formula')
        ->assertSee('Replace everywhere and delete')
        ->assertSee('Remove everywhere and delete')
        ->assertSee($compatible->display_name)
        ->assertDontSeeHtml('>'.$incompatible->display_name.' (Clay)</option>')
        ->assertSeeHtml('x-trap.noscroll="true"')
        ->assertSeeHtml('x-ref="initialFocus"')
        ->assertSeeHtml('x-on:ingredient-removal-closed.window=')
        ->assertSeeHtml('wire:loading.attr="disabled"');

    expect(ingredientButtonIsDisabled($component->html(), 'Manage removal of Lavender Essential Oil'))->toBeFalse();

    $component
        ->set('replacementIngredientId', $compatible->id)
        ->call('cancelDelete')
        ->assertSet('pendingDeleteId', null)
        ->assertSet('replacementIngredientId', null);
});

it('searches authorized replacement candidates while preserving a selected replacement', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $source = catalogPrivateIngredient($user, IngredientCategory::EssentialOil, 'Original Lavender');
    $lavender = Ingredient::factory()->create([
        'category' => IngredientCategory::FragranceOil,
        'display_name' => 'Lavender Fragrance',
    ]);
    $bergamot = Ingredient::factory()->create([
        'category' => IngredientCategory::Co2Extract,
        'display_name' => 'Bergamot CO2',
    ]);
    $inaccessible = catalogPrivateIngredient($otherUser, IngredientCategory::EssentialOil, 'Secret Lavender');
    $incompatible = Ingredient::factory()->create([
        'category' => IngredientCategory::Clay,
        'display_name' => 'Lavender Clay',
    ]);
    catalogFormulaUsage($user, $source, 'Searchable Formula');

    $this->actingAs($user);

    $component = Livewire::test(IngredientsIndex::class)
        ->call('confirmDelete', $source->id)
        ->assertSeeHtml('aria-label="Search replacement ingredients"')
        ->assertViewHas(
            'replacementCandidates',
            fn ($candidates): bool => collect($candidates->modelKeys())->sort()->values()->all()
                === collect([$lavender->id, $bergamot->id])->sort()->values()->all(),
        )
        ->set('replacementSearch', 'lavender')
        ->assertViewHas('replacementCandidates', fn ($candidates): bool => $candidates->modelKeys() === [$lavender->id])
        ->set('replacementIngredientId', $lavender->id)
        ->set('replacementSearch', 'no matching candidate')
        ->assertViewHas('replacementCandidates', fn ($candidates): bool => $candidates->modelKeys() === [$lavender->id]);

    expect($component->get('replacementSearch'))->toBe('no matching candidate');

    $component
        ->call('cancelDelete')
        ->assertSet('replacementSearch', '')
        ->assertDispatched('ingredient-removal-closed');
});

it('closes a stale ingredient dialog and shows a persistent page error', function () {
    $user = User::factory()->create();
    $source = catalogPrivateIngredient($user, IngredientCategory::Additive, 'Vanishing Additive');

    $this->actingAs($user);

    $component = Livewire::test(IngredientsIndex::class)
        ->call('confirmDelete', $source->id)
        ->assertSet('pendingDeleteId', $source->id);

    $source->delete();

    $component
        ->call('deleteIngredient')
        ->assertSet('pendingDeleteId', null)
        ->assertSet('replacementIngredientId', null)
        ->assertSet('replacementSearch', '')
        ->assertSee('The ingredient is no longer available in your private catalog.')
        ->assertDontSee('Vanishing Additive')
        ->assertDispatched('ingredient-removal-closed');
});

it('replaces a used ingredient everywhere and closes the dialog', function () {
    $user = User::factory()->create();
    $source = catalogPrivateIngredient($user, IngredientCategory::Additive, 'Old Additive');
    $replacement = catalogPrivateIngredient($user, IngredientCategory::Additive, 'New Additive');
    [, , $recipeItem] = catalogFormulaUsage($user, $source, 'Updated Formula');

    $this->actingAs($user);

    Livewire::test(IngredientsIndex::class)
        ->call('confirmDelete', $source->id)
        ->set('replacementIngredientId', (string) $replacement->id)
        ->call('replaceEverywhereAndDelete')
        ->assertHasNoErrors()
        ->assertSet('pendingDeleteId', null)
        ->assertSet('replacementIngredientId', null)
        ->assertSee('Old Additive was replaced everywhere and deleted.')
        ->assertSee('Mine (1)');

    expect($source->fresh())->toBeNull()
        ->and($recipeItem->fresh()->ingredient_id)->toBe($replacement->id);
});

it('removes a used ingredient everywhere and closes the dialog', function () {
    $user = User::factory()->create();
    $source = catalogPrivateIngredient($user, IngredientCategory::Additive, 'Obsolete Additive');
    [, , $recipeItem] = catalogFormulaUsage($user, $source, 'Reduced Formula');

    $this->actingAs($user);

    Livewire::test(IngredientsIndex::class)
        ->call('confirmDelete', $source->id)
        ->call('removeEverywhereAndDelete')
        ->assertHasNoErrors()
        ->assertSet('pendingDeleteId', null)
        ->assertSee('Obsolete Additive was removed everywhere and deleted.')
        ->assertSee('Mine (0)');

    expect($source->fresh())->toBeNull()
        ->and($recipeItem->fresh())->toBeNull();
});

it('blocks automatic removal when affected formulas cannot all be edited without leaking inaccessible names', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $workspaceOwner = User::factory()->create();
    $source = catalogPrivateIngredient($user, IngredientCategory::Additive, 'Shared Additive');
    $replacement = catalogPrivateIngredient($user, IngredientCategory::Additive, 'Replacement Additive');
    $workspace = Workspace::factory()->for($workspaceOwner, 'owner')->create();
    WorkspaceMember::factory()->for($workspace)->for($user)->create([
        'role' => WorkspaceMemberRole::Viewer,
    ]);
    $visibleBlockedRecipe = Recipe::factory()->create([
        'name' => 'Visible Shared Formula',
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Workspace,
    ]);
    $visibleVersion = RecipeVersion::factory()->create([
        'recipe_id' => $visibleBlockedRecipe->id,
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Workspace,
    ]);
    RecipeItem::factory()->create([
        'recipe_version_id' => $visibleVersion->id,
        'recipe_phase_id' => null,
        'ingredient_id' => $source->id,
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Workspace,
    ]);
    catalogFormulaUsage($otherUser, $source, 'Secret Formula Name');

    $this->actingAs($user);

    $component = Livewire::test(IngredientsIndex::class)
        ->call('confirmDelete', $source->id)
        ->assertSee('Visible Shared Formula')
        ->assertSeeHtml('href="'.route('recipes.edit', $visibleBlockedRecipe).'"')
        ->assertSee('1 additional formula cannot be edited')
        ->assertDontSee('Secret Formula Name');

    expect(ingredientButtonIsDisabled($component->html(), null, 'replaceEverywhereAndDelete'))->toBeTrue()
        ->and(ingredientButtonIsDisabled($component->html(), null, 'removeEverywhereAndDelete'))->toBeTrue();

    $component
        ->set('replacementIngredientId', $replacement->id)
        ->call('replaceEverywhereAndDelete')
        ->assertHasErrors(['ingredient'])
        ->assertSet('pendingDeleteId', $source->id)
        ->call('removeEverywhereAndDelete')
        ->assertHasErrors(['ingredient'])
        ->assertSet('pendingDeleteId', $source->id)
        ->assertDontSee('Secret Formula Name');

    expect($source->fresh())->not->toBeNull();
});

it('refuses tampered ingredient and replacement identifiers', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $source = catalogPrivateIngredient($user, IngredientCategory::Additive, 'Owned Additive');
    $otherSource = catalogPrivateIngredient($otherUser, IngredientCategory::Additive, 'Other Private Additive');
    catalogFormulaUsage($user, $source, 'Protected Formula');

    $this->actingAs($user);

    Livewire::test(IngredientsIndex::class)
        ->call('confirmDelete', $otherSource->id)
        ->assertSet('pendingDeleteId', null);

    expect(fn () => Livewire::test(IngredientsIndex::class)
        ->set('pendingDeleteId', $source->id))
        ->toThrow(CannotUpdateLockedPropertyException::class);

    Livewire::test(IngredientsIndex::class)
        ->call('confirmDelete', $source->id)
        ->set('replacementIngredientId', $otherSource->id)
        ->call('replaceEverywhereAndDelete')
        ->assertHasErrors(['replacementIngredientId'])
        ->assertSet('pendingDeleteId', $source->id)
        ->assertSee('compatible with every affected formula');

    expect($source->fresh())->not->toBeNull();
});

it('disables deleting a personal ingredient that is already used in costing', function () {
    $user = User::factory()->create();

    $ingredient = Ingredient::factory()->create([
        'category' => IngredientCategory::Additive,
        'display_name' => 'Locked Ingredient',
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
        'source_file' => 'user',
        'source_key' => 'USR-LOCKED',
    ]);

    $recipe = Recipe::factory()->create([
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
    ]);

    $recipeVersion = RecipeVersion::factory()->create([
        'recipe_id' => $recipe->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
    ]);

    $costing = RecipeVersionCosting::query()->create([
        'recipe_version_id' => $recipeVersion->id,
        'user_id' => $user->id,
        'oil_weight_for_costing' => 1000,
        'oil_unit_for_costing' => 'g',
        'units_produced' => 10,
        'currency' => 'EUR',
    ]);

    RecipeVersionCostingItem::query()->create([
        'recipe_version_costing_id' => $costing->id,
        'ingredient_id' => $ingredient->id,
        'phase_key' => 'additives',
        'position' => 1,
        'price_per_kg' => 12.4,
    ]);

    $this->actingAs($user);

    Livewire::test(IngredientsIndex::class)
        ->call('deleteIngredient', $ingredient->id);

    expect(Ingredient::query()->whereKey($ingredient->id)->exists())->toBeTrue();
});

it('disables deleting a personal ingredient that is used in a recipe formula', function () {
    $user = User::factory()->create();

    $ingredient = Ingredient::factory()->create([
        'category' => IngredientCategory::Additive,
        'display_name' => 'In-Formula Ingredient',
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
        'source_file' => 'user',
        'source_key' => 'USR-FORMULA',
    ]);

    $recipe = Recipe::factory()->create([
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
    ]);

    $recipeVersion = RecipeVersion::factory()->create([
        'recipe_id' => $recipe->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
    ]);

    RecipeItem::query()->create([
        'recipe_version_id' => $recipeVersion->id,
        'ingredient_id' => $ingredient->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
        'position' => 1,
        'percentage' => 5.0,
    ]);

    $this->actingAs($user);

    Livewire::test(IngredientsIndex::class)
        ->call('deleteIngredient', $ingredient->id);

    expect(Ingredient::query()->whereKey($ingredient->id)->exists())->toBeTrue();
});

it('explains which formula versions protect a private ingredient from deletion', function () {
    $user = User::factory()->create();
    $ingredient = Ingredient::factory()->create([
        'display_name' => 'Protected Preservative',
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
    ]);
    $recipe = Recipe::factory()->create([
        'name' => 'Recovery Cream',
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
    ]);
    $versions = collect([1, 2])->map(fn (int $versionNumber): RecipeVersion => RecipeVersion::factory()
        ->create([
            'recipe_id' => $recipe->id,
            'owner_type' => OwnerType::User,
            'owner_id' => $user->id,
            'visibility' => Visibility::Private,
            'version_number' => $versionNumber,
            'is_current' => false,
        ]));

    foreach ($versions as $version) {
        RecipeItem::factory()->create([
            'recipe_version_id' => $version->id,
            'recipe_phase_id' => null,
            'ingredient_id' => $ingredient->id,
            'owner_type' => OwnerType::User,
            'owner_id' => $user->id,
            'visibility' => Visibility::Private,
        ]);
    }

    $this->actingAs($user);

    $component = Livewire::test(IngredientsIndex::class)
        ->assertSee('Used in 1 formula')
        ->assertSeeHtml('aria-expanded="false"')
        ->assertSeeHtml('aria-controls="ingredient-usage-'.$ingredient->id.'"');

    $collapsedControl = ingredientUsageControlState($component->html());

    expect($collapsedControl)
        ->toMatchArray([
            'controlled_id' => 'ingredient-usage-'.$ingredient->id,
            'controlled_element_exists' => true,
            'controlled_element_hidden' => true,
            'control_disabled' => false,
        ]);

    $component->call('toggleUsage', $ingredient->id)
        ->assertSet('expandedUsageIngredientId', $ingredient->id)
        ->assertSeeHtml('aria-expanded="true"')
        ->assertSee($recipe->name)
        ->assertSeeHtml('href="'.route('recipes.edit', $recipe->id).'"')
        ->assertDontSee('saved backup')
        ->assertDontSee('recoverable formula records');

    $expandedControl = ingredientUsageControlState($component->html());

    expect($expandedControl)
        ->toMatchArray([
            'controlled_id' => 'ingredient-usage-'.$ingredient->id,
            'controlled_element_exists' => true,
            'controlled_element_hidden' => false,
            'control_disabled' => false,
        ]);

    $component->call('toggleUsage', $ingredient->id)
        ->assertSet('expandedUsageIngredientId', null)
        ->call('confirmDelete', $ingredient->id)
        ->assertSee('Replace everywhere and delete');

    expect(Ingredient::query()->whereKey($ingredient->id)->exists())->toBeTrue();
});

it('does not expose saved backup counts in ingredient usage copy', function () {
    $user = User::factory()->create();
    $ingredient = Ingredient::factory()->create([
        'display_name' => 'Single Backup Preservative',
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
    ]);
    $recipe = Recipe::factory()->create([
        'name' => 'Single Backup Cream',
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
    ]);
    $backup = RecipeVersion::factory()->create([
        'recipe_id' => $recipe->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
        'is_current' => false,
    ]);

    RecipeItem::factory()->create([
        'recipe_version_id' => $backup->id,
        'recipe_phase_id' => null,
        'ingredient_id' => $ingredient->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
    ]);

    $this->actingAs($user);

    Livewire::test(IngredientsIndex::class)
        ->call('toggleUsage', $ingredient->id)
        ->assertSee($recipe->name)
        ->assertDontSee('saved backup');
});

it('protects draft-only formula usage without labeling the draft as a saved backup', function () {
    $user = User::factory()->create();
    $ingredient = Ingredient::factory()->create([
        'display_name' => 'Draft Preservative',
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
    ]);
    $recipe = Recipe::factory()->create([
        'name' => 'Unpublished Recovery Cream',
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
    ]);
    $draft = RecipeVersion::factory()->create([
        'recipe_id' => $recipe->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
        'is_current' => true,
    ]);

    RecipeItem::factory()->create([
        'recipe_version_id' => $draft->id,
        'recipe_phase_id' => null,
        'ingredient_id' => $ingredient->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
    ]);

    $this->actingAs($user);

    Livewire::test(IngredientsIndex::class)
        ->assertSee('Used in 1 formula')
        ->call('toggleUsage', $ingredient->id)
        ->assertSee($recipe->name)
        ->assertDontSee('saved backup')
        ->assertDontSee('recoverable formula records')
        ->call('confirmDelete', $ingredient->id)
        ->assertSee('Replace everywhere and delete');

    expect(Ingredient::query()->whereKey($ingredient->id)->exists())->toBeTrue();
});

it('clears an expanded ingredient usage disclosure when the catalog context changes', function () {
    $user = User::factory()->create();
    $ingredient = Ingredient::factory()->create([
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
    ]);

    $this->actingAs($user);

    $component = Livewire::test(IngredientsIndex::class)
        ->call('toggleUsage', $ingredient->id)
        ->set('search', 'changed')
        ->assertSet('expandedUsageIngredientId', null)
        ->call('toggleUsage', $ingredient->id)
        ->call('setOwnershipFilter', 'mine')
        ->assertSet('expandedUsageIngredientId', null)
        ->call('toggleUsage', $ingredient->id)
        ->call('sortBy', 'category')
        ->assertSet('expandedUsageIngredientId', null)
        ->call('toggleUsage', $ingredient->id)
        ->set('perPage', 50)
        ->assertSet('expandedUsageIngredientId', null)
        ->call('toggleUsage', $ingredient->id)
        ->call('setPage', 2)
        ->assertSet('expandedUsageIngredientId', null)
        ->call('toggleUsage', $ingredient->id)
        ->call('confirmDelete', $ingredient->id)
        ->call('deleteIngredient')
        ->assertSet('expandedUsageIngredientId', null);

    expect(Ingredient::query()->whereKey($ingredient->id)->exists())->toBeFalse();
});

it('uses a non-allowing private ingredient fallback for guests', function () {
    Livewire::test(IngredientsIndex::class)
        ->assertViewHas(
            'privateIngredientUsage',
            fn (array $usage): bool => $usage['used'] === 0
                && $usage['limit'] === null
                && $usage['allowed'] === false,
        );
});

it('shows private ingredient allowance without a null limit', function () {
    $user = User::factory()->create();
    $plan = Plan::factory()->create();

    $user->entitlements()->create([
        'plan_id' => $plan->id,
        'status' => 'active',
        'starts_at' => now(),
    ]);

    Ingredient::factory()->create([
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
    ]);

    $this->actingAs($user)
        ->get(route('ingredients.index'))
        ->assertSuccessful()
        ->assertSee('1 private ingredient')
        ->assertDontSee('1 of')
        ->assertDontSee('1 /')
        ->assertDontSee('of null');
});

it('pluralizes an unlimited private ingredient allowance', function () {
    $user = User::factory()->create();
    $plan = Plan::factory()->create();

    $user->entitlements()->create([
        'plan_id' => $plan->id,
        'status' => 'active',
        'starts_at' => now(),
    ]);

    Ingredient::factory()
        ->count(2)
        ->create([
            'owner_type' => OwnerType::User,
            'owner_id' => $user->id,
            'visibility' => Visibility::Private,
        ]);

    $this->actingAs($user)
        ->get(route('ingredients.index'))
        ->assertSuccessful()
        ->assertSee('2 private ingredients')
        ->assertDontSee('2 of')
        ->assertDontSee('of null');
});

it('does not allow editing another users private ingredient', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $ingredient = Ingredient::factory()->create([
        'category' => IngredientCategory::Additive,
        'display_name' => 'Other User Ingredient',
        'owner_type' => OwnerType::User,
        'owner_id' => $otherUser->id,
        'visibility' => Visibility::Private,
        'source_file' => 'user',
        'source_key' => 'USR-OTHER',
    ]);

    $this->actingAs($user)
        ->get(route('ingredients.edit', $ingredient->id))
        ->assertNotFound();
});

it('does not allow editing a platform ingredient from the public ingredient editor route', function () {
    $user = User::factory()->create();

    $ingredient = Ingredient::factory()->create([
        'category' => IngredientCategory::Additive,
        'display_name' => 'Platform Glycerin',
        'owner_type' => null,
        'owner_id' => null,
        'source_file' => 'platform',
        'source_key' => 'PLATFORM-GLYCERIN',
    ]);

    $this->actingAs($user)
        ->get(route('ingredients.edit', $ingredient->id))
        ->assertNotFound();
});

it('does not delete a platform ingredient if a table action call is forced', function () {
    $user = User::factory()->create();

    $ingredient = Ingredient::factory()->create([
        'category' => IngredientCategory::Additive,
        'display_name' => 'Platform Glycerin',
        'owner_type' => null,
        'owner_id' => null,
        'source_file' => 'platform',
        'source_key' => 'PLATFORM-GLYCERIN',
    ]);

    $this->actingAs($user);

    Livewire::test(IngredientsIndex::class)
        ->call('deleteIngredient', $ingredient->id);

    expect(Ingredient::query()->whereKey($ingredient->id)->exists())->toBeTrue();
});

/**
 * @return array{controlled_id: string, controlled_element_exists: bool, controlled_element_hidden: bool, control_disabled: bool}
 */
function ingredientUsageControlState(string $html): array
{
    $previousLibxmlSetting = libxml_use_internal_errors(true);
    $document = new DOMDocument;
    $document->loadHTML($html);
    libxml_clear_errors();
    libxml_use_internal_errors($previousLibxmlSetting);

    $control = (new DOMXPath($document))->query('//button[@aria-controls]')->item(0);

    expect($control)->toBeInstanceOf(DOMElement::class);

    $controlledId = $control->getAttribute('aria-controls');
    $controlledElement = $document->getElementById($controlledId);

    return [
        'controlled_id' => $controlledId,
        'controlled_element_exists' => $controlledElement instanceof DOMElement,
        'controlled_element_hidden' => $controlledElement?->hasAttribute('hidden') ?? false,
        'control_disabled' => $control->hasAttribute('disabled'),
    ];
}

function ingredientButtonIsDisabled(string $html, ?string $ariaLabel = null, ?string $wireClick = null): bool
{
    $previousLibxmlSetting = libxml_use_internal_errors(true);
    $document = new DOMDocument;
    $document->loadHTML($html);
    libxml_clear_errors();
    libxml_use_internal_errors($previousLibxmlSetting);

    foreach ((new DOMXPath($document))->query('//button') as $button) {
        if (! $button instanceof DOMElement) {
            continue;
        }

        if ($ariaLabel !== null && $button->getAttribute('aria-label') !== $ariaLabel) {
            continue;
        }

        if ($wireClick !== null && $button->getAttribute('wire:click') !== $wireClick) {
            continue;
        }

        return $button->hasAttribute('disabled');
    }

    throw new RuntimeException('Expected ingredient action button was not rendered.');
}

function catalogPrivateIngredient(
    User $user,
    IngredientCategory $category,
    string $name,
): Ingredient {
    return Ingredient::factory()->create([
        'category' => $category,
        'display_name' => $name,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
        'source_file' => 'user',
    ]);
}

/** @return array{Recipe, RecipeVersion, RecipeItem} */
function catalogFormulaUsage(User $user, Ingredient $ingredient, string $recipeName): array
{
    $recipe = Recipe::factory()->create([
        'name' => $recipeName,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'created_by' => $user->id,
        'visibility' => Visibility::Private,
    ]);
    $version = RecipeVersion::factory()->create([
        'recipe_id' => $recipe->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
    ]);
    $item = RecipeItem::factory()->create([
        'recipe_version_id' => $version->id,
        'recipe_phase_id' => null,
        'ingredient_id' => $ingredient->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
    ]);

    return [$recipe, $version, $item];
}

it('renders the public ingredient create page for signed in users', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('ingredients.create'))
        ->assertSuccessful()
        ->assertSee('Identity')
        ->assertSee('CAS number')
        ->assertSee('EINECS / EC number')
        ->assertSee('Organic')
        ->assertDontSee('Allergens')
        ->assertDontSee('IFRA guidance');
});

it('keeps one responsive create action visible at the bottom of the ingredient editor', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->get(route('ingredients.create'))
        ->assertSuccessful();

    $saveAction = ingredientSaveActionState($response->getContent());

    expect($saveAction['submit_count'])->toBe(1)
        ->and($saveAction['label'])->toBe('Create ingredient')
        ->and($saveAction['bar_classes'])->toContain('sticky', 'bottom-0')
        ->and($saveAction['button_classes'])->toContain('w-full', 'sm:w-auto');
});

it('keeps one responsive save action visible at the bottom of the ingredient editor', function () {
    $user = User::factory()->create();
    $ingredient = catalogPrivateIngredient($user, IngredientCategory::Additive, 'My Glycerin');

    $response = $this->actingAs($user)
        ->get(route('ingredients.edit', $ingredient->id))
        ->assertSuccessful();

    $saveAction = ingredientSaveActionState($response->getContent());

    expect($saveAction['submit_count'])->toBe(1)
        ->and($saveAction['label'])->toBe('Save ingredient')
        ->and($saveAction['bar_classes'])->toContain('sticky', 'bottom-0')
        ->and($saveAction['button_classes'])->toContain('w-full', 'sm:w-auto');
});

/**
 * @return array{submit_count: int, label: string, bar_classes: list<string>, button_classes: list<string>}
 */
function ingredientSaveActionState(string $html): array
{
    $previousLibxmlSetting = libxml_use_internal_errors(true);
    $document = new DOMDocument;
    $document->loadHTML($html);
    libxml_clear_errors();
    libxml_use_internal_errors($previousLibxmlSetting);

    $xpath = new DOMXPath($document);
    $saveBar = $xpath->query('//*[@data-ingredient-save-bar]')->item(0);

    expect($saveBar)->toBeInstanceOf(DOMElement::class);

    $ingredientForm = $xpath->query('ancestor::form[1]', $saveBar)->item(0);

    expect($ingredientForm)->toBeInstanceOf(DOMElement::class);

    $submitButtons = $xpath->query('.//button[@type="submit"]', $ingredientForm);
    $saveButton = $xpath->query('.//button[@type="submit"]', $saveBar)->item(0);

    expect($saveButton)->toBeInstanceOf(DOMElement::class);

    return [
        'submit_count' => $submitButtons->length,
        'label' => trim($saveButton->textContent),
        'bar_classes' => preg_split('/\s+/', trim($saveBar->getAttribute('class'))) ?: [],
        'button_classes' => preg_split('/\s+/', trim($saveButton->getAttribute('class'))) ?: [],
    ];
}
