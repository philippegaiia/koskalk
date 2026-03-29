<?php

use App\IngredientCategory;
use App\Models\Ingredient;
use App\Models\IngredientVersion;
use App\Models\ProductFamily;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use App\OwnerType;
use App\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders the simplified dashboard with creation buttons and saved recipes', function () {
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
        'name' => $recipe->name,
        'is_draft' => true,
        'version_number' => 1,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->assertSee('Create soap formula')
        ->assertSee('Create formula')
        ->assertSee('Dashboard Test Formula')
        ->assertSee('Personal ingredients');
});

it('does not show recipes from another user on the dashboard', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);

    $visibleRecipe = Recipe::factory()->create([
        'product_family_id' => $soapFamily->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
        'name' => 'Visible Dashboard Formula',
        'slug' => 'visible-dashboard-formula',
    ]);

    $hiddenRecipe = Recipe::factory()->create([
        'product_family_id' => $soapFamily->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $otherUser->id,
        'visibility' => Visibility::Private,
        'name' => 'Hidden Dashboard Formula',
        'slug' => 'hidden-dashboard-formula',
    ]);

    RecipeVersion::factory()->create([
        'recipe_id' => $visibleRecipe->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
        'name' => $visibleRecipe->name,
        'is_draft' => true,
        'version_number' => 1,
    ]);

    RecipeVersion::factory()->create([
        'recipe_id' => $hiddenRecipe->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $otherUser->id,
        'visibility' => Visibility::Private,
        'name' => $hiddenRecipe->name,
        'is_draft' => true,
        'version_number' => 1,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->assertSee('Visible Dashboard Formula')
        ->assertDontSee('Hidden Dashboard Formula');
});

it('shows the current users personal ingredient summary on the dashboard', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $visibleIngredient = Ingredient::factory()->create([
        'category' => IngredientCategory::Clay,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
        'source_file' => 'user',
        'source_key' => 'USR-CLAY',
    ]);

    IngredientVersion::factory()->for($visibleIngredient)->create([
        'display_name' => 'French Green Clay',
    ]);

    $hiddenIngredient = Ingredient::factory()->create([
        'category' => IngredientCategory::Glycol,
        'owner_type' => OwnerType::User,
        'owner_id' => $otherUser->id,
        'visibility' => Visibility::Private,
        'source_file' => 'user',
        'source_key' => 'USR-GLY',
    ]);

    IngredientVersion::factory()->for($hiddenIngredient)->create([
        'display_name' => 'Propylene Glycol',
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->assertSee('French Green Clay')
        ->assertDontSee('Propylene Glycol')
        ->assertSee('Browse my ingredients');
});
