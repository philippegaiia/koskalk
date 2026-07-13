<?php

use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\RecipeVersion;
use App\Models\RecipeVersionCosting;
use App\Models\RecipeVersionCostingItem;
use App\Models\User;
use App\OwnerType;
use App\Services\IngredientFormulaUsageService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('groups direct usage by recipe and counts unique saved versions', function () {
    $user = User::factory()->create();
    $ingredient = Ingredient::factory()->create([
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
    ]);
    $recipe = Recipe::factory()->create([
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'name' => 'Lavender Soap',
    ]);
    $firstVersion = RecipeVersion::factory()->create([
        'recipe_id' => $recipe->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'version_number' => 1,
        'is_current' => false,
    ]);
    $secondVersion = RecipeVersion::factory()->create([
        'recipe_id' => $recipe->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'version_number' => 2,
        'is_current' => false,
    ]);

    foreach ([$firstVersion, $secondVersion] as $version) {
        RecipeItem::factory()->create([
            'recipe_version_id' => $version->id,
            'recipe_phase_id' => null,
            'ingredient_id' => $ingredient->id,
            'owner_type' => OwnerType::User,
            'owner_id' => $user->id,
        ]);
    }

    $usage = app(IngredientFormulaUsageService::class)->forIngredients(
        $user,
        collect([$ingredient]),
    );

    expect($usage[$ingredient->id])->toHaveCount(1)
        ->and($usage[$ingredient->id][0])->toMatchArray([
            'recipe_id' => $recipe->id,
            'name' => $recipe->name,
            'version_count' => 2,
            'url' => route('recipes.edit', $recipe->id),
        ]);
});

it('resolves costing-only usage to its recipe', function () {
    $user = User::factory()->create();
    $ingredient = Ingredient::factory()->create();
    $recipe = Recipe::factory()->create([
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'name' => 'Costed Formula',
    ]);
    $version = RecipeVersion::factory()->create([
        'recipe_id' => $recipe->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
    ]);
    $costing = RecipeVersionCosting::query()->create([
        'recipe_version_id' => $version->id,
        'user_id' => $user->id,
        'currency' => 'EUR',
    ]);

    RecipeVersionCostingItem::query()->create([
        'recipe_version_costing_id' => $costing->id,
        'ingredient_id' => $ingredient->id,
        'phase_key' => 'main',
        'position' => 1,
    ]);

    $usage = app(IngredientFormulaUsageService::class)->forIngredients(
        $user,
        collect([$ingredient]),
    );

    expect($usage[$ingredient->id][0]['recipe_id'])->toBe($recipe->id);
});

it('omits formula usage belonging to another user', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $otherUsersIngredient = Ingredient::factory()->create([
        'owner_type' => OwnerType::User,
        'owner_id' => $otherUser->id,
    ]);
    $otherUsersRecipe = Recipe::factory()->create([
        'owner_type' => OwnerType::User,
        'owner_id' => $otherUser->id,
    ]);
    $otherUsersVersion = RecipeVersion::factory()->create([
        'recipe_id' => $otherUsersRecipe->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $otherUser->id,
    ]);

    RecipeItem::factory()->create([
        'recipe_version_id' => $otherUsersVersion->id,
        'recipe_phase_id' => null,
        'ingredient_id' => $otherUsersIngredient->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $otherUser->id,
    ]);

    $usage = app(IngredientFormulaUsageService::class)->forIngredients(
        $user,
        collect([$otherUsersIngredient]),
    );

    expect($usage)->not->toHaveKey($otherUsersIngredient->id);
});

it('returns an empty array for an empty ingredient collection', function () {
    $user = User::factory()->create();

    expect(app(IngredientFormulaUsageService::class)->forIngredients($user, collect()))
        ->toBe([]);
});
