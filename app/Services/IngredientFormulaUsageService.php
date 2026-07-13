<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\RecipeItem;
use App\Models\RecipeVersionCostingItem;
use App\Models\User;
use App\OwnerType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;

class IngredientFormulaUsageService
{
    /**
     * @param  Collection<int, Ingredient>  $ingredients
     * @return array<int, array<int, array{recipe_id: int, name: string, version_count: int, url: string}>>
     */
    public function forIngredients(User $user, Collection $ingredients): array
    {
        $ingredientIds = $ingredients
            ->pluck('id')
            ->filter(fn (mixed $ingredientId): bool => is_int($ingredientId))
            ->unique()
            ->values();

        if ($ingredientIds->isEmpty()) {
            return [];
        }

        $recipeConstraint = fn (Builder $query): Builder => $query
            ->withoutGlobalScopes()
            ->where('owner_type', OwnerType::User->value)
            ->where('owner_id', $user->id);

        $recipeEagerConstraint = fn (Relation $query): Relation => $query
            ->withoutGlobalScopes()
            ->where('owner_type', OwnerType::User->value)
            ->where('owner_id', $user->id);

        $recipeVersionConstraint = fn (Builder $query): Builder => $query
            ->withoutGlobalScopes()
            ->whereHas('recipe', $recipeConstraint);

        $recipeItems = RecipeItem::withoutGlobalScopes()
            ->whereIn('ingredient_id', $ingredientIds)
            ->whereHas('recipeVersion', $recipeVersionConstraint)
            ->with([
                'recipeVersion' => fn (Relation $query): Relation => $query
                    ->withoutGlobalScopes()
                    ->with(['recipe' => $recipeEagerConstraint]),
            ])
            ->get();

        $costingItems = RecipeVersionCostingItem::query()
            ->whereIn('ingredient_id', $ingredientIds)
            ->whereHas(
                'costing',
                fn (Builder $query): Builder => $query->whereHas('recipeVersion', $recipeVersionConstraint),
            )
            ->with([
                'costing' => fn (Relation $query): Relation => $query->with([
                    'recipeVersion' => fn (Relation $query): Relation => $query
                        ->withoutGlobalScopes()
                        ->with(['recipe' => $recipeEagerConstraint]),
                ]),
            ])
            ->get();

        $usageRows = $recipeItems
            ->map(function (RecipeItem $recipeItem): array {
                $recipe = $recipeItem->recipeVersion->recipe;

                return [
                    'ingredient_id' => $recipeItem->ingredient_id,
                    'recipe_id' => $recipe->id,
                    'recipe_name' => $recipe->name,
                    'version_id' => $recipeItem->recipe_version_id,
                ];
            })
            ->concat($costingItems->map(function (RecipeVersionCostingItem $costingItem): array {
                $recipeVersion = $costingItem->costing->recipeVersion;
                $recipe = $recipeVersion->recipe;

                return [
                    'ingredient_id' => $costingItem->ingredient_id,
                    'recipe_id' => $recipe->id,
                    'recipe_name' => $recipe->name,
                    'version_id' => $recipeVersion->id,
                ];
            }));

        return $usageRows
            ->groupBy('ingredient_id')
            ->map(fn (Collection $ingredientRows): array => $ingredientRows
                ->groupBy('recipe_id')
                ->map(function (Collection $recipeRows): array {
                    $firstRow = $recipeRows->first();

                    return [
                        'recipe_id' => $firstRow['recipe_id'],
                        'name' => $firstRow['recipe_name'],
                        'version_count' => $recipeRows->pluck('version_id')->unique()->count(),
                        'url' => route('recipes.edit', $firstRow['recipe_id']),
                    ];
                })
                ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
                ->values()
                ->all())
            ->all();
    }
}
