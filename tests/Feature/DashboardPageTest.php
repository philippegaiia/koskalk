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

it('renders the simplified dashboard with creation buttons, stat cards, and user identity', function () {
    $user = User::factory()->create([
        'name' => 'Marie Maker',
        'email' => 'marie@example.com',
    ]);
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
        'locked_at' => now(),
        'locked_by' => $user->id,
    ]);

    RecipeVersion::factory()->create([
        'recipe_id' => $recipe->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
        'name' => 'Dashboard Published Formula',
        'is_current' => false,
        'version_number' => 2,
        'saved_at' => now(),
    ]);
    RecipeVersion::factory()->create([
        'recipe_id' => $recipe->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
        'name' => $recipe->name,
        'is_current' => true,
        'version_number' => 3,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->assertSee('Create a product')
        ->assertSee('New soap product')
        ->assertSee('New cosmetic product')
        ->assertSee('Your products')
        ->assertSee('Products')
        ->assertSee('Ingredients')
        ->assertSee('Marie Maker')
        ->assertSee('marie@example.com')
        ->assertSee('Free account')
        ->assertDontSee('mt-3 flex min-w-0 items-start gap-3', false)
        ->assertDontSee('max-w-44 truncate text-xs text-[var(--color-ink-soft)]', false)
        ->assertSee('Locked products')
        ->assertDontSee('Welcome to your formulation workspace.')
        ->assertDontSee('More modules coming soon.');
});

it('shows recipe and lock counts for the current user only', function () {
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
        'locked_at' => now(),
        'locked_by' => $user->id,
    ]);

    RecipeVersion::factory()->create([
        'recipe_id' => $userRecipe->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
        'name' => $userRecipe->name,
        'is_current' => true,
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
    $lockedFormulaCount = $response->viewData('lockedFormulaCount');

    expect($recipeCount)->toBe(1)
        ->and($lockedFormulaCount)->toBe(1);
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
