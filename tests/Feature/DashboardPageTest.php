<?php

use App\IngredientCategory;
use App\Models\Ingredient;
use App\Models\ProductFamily;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use App\OwnerType;
use App\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders the simplified dashboard with creation buttons and stat cards', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $recipe = Recipe::factory()->create([
        'product_family_id' => $soapFamily->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
        'name' => 'Dashboard Test Formula',
        'slug' => 'dashboard-test-formula',
    ]);

    RecipeVersion::factory()->create([
        'recipe_id' => $recipe->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
        'name' => 'Dashboard Published Formula',
        'is_draft' => false,
        'version_number' => 2,
        'saved_at' => now(),
    ]);
    RecipeVersion::factory()->create([
        'recipe_id' => $recipe->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
        'name' => $recipe->name,
        'is_draft' => true,
        'version_number' => 3,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->assertSee('Create soap formula')
        ->assertSee('Create cosmetic formula')
        ->assertSee('Recipes')
        ->assertSee('Ingredients')
        ->assertSee('Drafts');
});

it('shows recipe and draft counts for the current user only', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);

    $userRecipe = Recipe::factory()->create([
        'product_family_id' => $soapFamily->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
        'name' => 'User Recipe',
        'slug' => 'user-recipe',
    ]);

    RecipeVersion::factory()->create([
        'recipe_id' => $userRecipe->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
        'name' => $userRecipe->name,
        'is_draft' => true,
        'version_number' => 1,
    ]);

    Recipe::factory()->create([
        'product_family_id' => $soapFamily->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $otherUser->id,
        'visibility' => Visibility::Private,
        'name' => 'Other User Recipe',
        'slug' => 'other-user-recipe',
    ]);

    $response = $this->actingAs($user)
        ->get(route('dashboard'));

    $response->assertSuccessful();

    $recipeCount = $response->viewData('recipeCount');
    expect($recipeCount)->toBe(1);
});

it('shows the current users personal ingredient count on the dashboard', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    Ingredient::factory()->create([
        'category' => IngredientCategory::Clay,
        'display_name' => 'French Green Clay',
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
        'source_file' => 'user',
        'source_key' => 'USR-CLAY',
    ]);

    Ingredient::factory()->create([
        'category' => IngredientCategory::Glycol,
        'display_name' => 'Propylene Glycol',
        'owner_type' => OwnerType::User,
        'owner_id' => $otherUser->id,
        'visibility' => Visibility::Private,
        'source_file' => 'user',
        'source_key' => 'USR-GLY',
    ]);

    $response = $this->actingAs($user)
        ->get(route('dashboard'));

    $response->assertSuccessful();

    $ingredientCount = $response->viewData('ingredientCount');
    expect($ingredientCount)->toBe(1);
});
