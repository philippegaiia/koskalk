<?php

use App\Models\ProductFamily;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use App\OwnerType;
use App\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('shows saved recipes on the recipes index page', function () {
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
        'name' => 'Olive Coconut Bar',
        'slug' => 'olive-coconut-bar',
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
    RecipeVersion::factory()->create([
        'recipe_id' => $recipe->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
        'name' => 'Published Olive Coconut Bar',
        'is_draft' => false,
        'version_number' => 2,
        'saved_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('recipes.index'))
        ->assertSuccessful()
        ->assertSee('Olive Coconut Bar')
        ->assertSee('Open working draft')
        ->assertSee('Use as draft')
        ->assertSee('Published Olive Coconut Bar');
});

it('only shows recipes that belong to the current user', function () {
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
        'name' => 'Visible Formula',
        'slug' => 'visible-formula',
    ]);

    $hiddenRecipe = Recipe::factory()->create([
        'product_family_id' => $soapFamily->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $otherUser->id,
        'visibility' => Visibility::Private,
        'name' => 'Hidden Formula',
        'slug' => 'hidden-formula',
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
        ->get(route('recipes.index'))
        ->assertSuccessful()
        ->assertSee('Visible Formula')
        ->assertDontSee('Hidden Formula');
});
