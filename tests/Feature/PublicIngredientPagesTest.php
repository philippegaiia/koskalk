<?php

use App\IngredientCategory;
use App\Livewire\Dashboard\IngredientsIndex;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\RecipeVersion;
use App\Models\RecipeVersionCosting;
use App\Models\RecipeVersionCostingItem;
use App\Models\User;
use App\OwnerType;
use App\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
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
        ->loadTable()
        ->assertCanSeeTableRecords([$glycerin, $clay])
        ->searchTable('Glycerin')
        ->assertCanSeeTableRecords([$glycerin])
        ->assertCanNotSeeTableRecords([$clay]);
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
        ->loadTable()
        ->callTableAction('delete', $ingredient);

    expect(Ingredient::query()->find($ingredient->id))->toBeNull()
        ->and(Storage::disk('public')->exists('ingredients/featured-images/delete-me.webp'))->toBeFalse()
        ->and(Storage::disk('public')->exists('ingredients/icons/delete-me.webp'))->toBeFalse();
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
        ->loadTable()
        ->assertTableActionDisabled('delete', $ingredient);
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
        ->loadTable()
        ->assertTableActionDisabled('delete', $ingredient);
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

    $component = Livewire::test(IngredientsIndex::class)
        ->loadTable();

    $deleteIngredient = new ReflectionMethod($component->instance(), 'deleteIngredient');

    expect($deleteIngredient->invoke($component->instance(), $ingredient))->toBeFalse();

    expect(Ingredient::query()->whereKey($ingredient->id)->exists())->toBeTrue();
});

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
