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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('deduplicates current direct formula usage while ignoring costing overlap', function (): void {
    $user = User::factory()->create();
    $ingredient = Ingredient::factory()->create([
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
    ]);
    $recipe = Recipe::factory()->create([
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'name' => 'Overlap Formula',
    ]);
    $version = RecipeVersion::factory()->create([
        'recipe_id' => $recipe->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'is_current' => true,
    ]);

    RecipeItem::factory()->create([
        'recipe_version_id' => $version->id,
        'recipe_phase_id' => null,
        'ingredient_id' => $ingredient->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
    ]);
    RecipeItem::factory()->create([
        'recipe_version_id' => $version->id,
        'recipe_phase_id' => null,
        'ingredient_id' => $ingredient->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'position' => 2,
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
    RecipeVersionCostingItem::query()->create([
        'recipe_version_costing_id' => $costing->id,
        'ingredient_id' => $ingredient->id,
        'phase_key' => 'main',
        'position' => 2,
    ]);

    $usage = app(IngredientFormulaUsageService::class)->forIngredients($user, collect([$ingredient]));

    expect($usage[$ingredient->id])->toHaveCount(1)
        ->and($usage[$ingredient->id][0])->toMatchArray([
            'recipe_id' => $recipe->id,
            'name' => 'Overlap Formula',
            'is_current' => true,
            'version_count' => 0,
        ]);
});

it('keeps its query count constant as formula usage grows', function (): void {
    $user = User::factory()->create();
    $ingredient = Ingredient::factory()->create([
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
    ]);

    createFormulaUsageFixture($user, $ingredient, 'Baseline Formula');
    $baselineQueryCount = count(ingredientFormulaUsageQueries($user, $ingredient));

    foreach (range(1, 8) as $formulaNumber) {
        createFormulaUsageFixture($user, $ingredient, 'Scaled Formula '.$formulaNumber);
    }

    $scaledQueryCount = count(ingredientFormulaUsageQueries($user, $ingredient));

    expect($baselineQueryCount)->toBe(3)
        ->and($scaledQueryCount)->toBe($baselineQueryCount);
});

it('indexes ingredient lookups used by direct and costing formula usage queries', function (): void {
    $recipeItemIndex = collect(Schema::getIndexes('recipe_items'))
        ->first(fn (array $index): bool => $index['columns'] === ['ingredient_id']);
    $costingItemIndex = collect(Schema::getIndexes('recipe_version_costing_items'))
        ->first(fn (array $index): bool => $index['columns'] === ['ingredient_id']);

    expect($recipeItemIndex)->not->toBeNull()
        ->and($costingItemIndex)->not->toBeNull();
});

/**
 * @return array<int, array<string, mixed>>
 */
function ingredientFormulaUsageQueries(User $user, Ingredient $ingredient): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        app(IngredientFormulaUsageService::class)->forIngredients($user, collect([$ingredient]));

        return DB::getQueryLog();
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
}

function createFormulaUsageFixture(User $user, Ingredient $ingredient, string $recipeName): void
{
    $recipe = Recipe::factory()->create([
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'name' => $recipeName,
    ]);
    $version = RecipeVersion::factory()->create([
        'recipe_id' => $recipe->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'is_current' => false,
    ]);
    RecipeItem::factory()->create([
        'recipe_version_id' => $version->id,
        'recipe_phase_id' => null,
        'ingredient_id' => $ingredient->id,
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
}
