<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\RecipeItem;
use App\Models\User;
use App\OwnerType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class IngredientFormulaUsageService
{
    public function __construct(
        private readonly IngredientCompositeDependencyService $compositeDependencyService,
    ) {}

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

        $usageIngredientIdsBySource = $this->compositeDependencyService->ingredientIdsBySource($ingredientIds);
        $sourceIngredientIdsByUsageIngredientId = collect($usageIngredientIdsBySource)
            ->flatMap(fn (Collection $usageIngredientIds, int $sourceIngredientId): Collection => $usageIngredientIds
                ->map(fn (int $usageIngredientId): array => [
                    'usage_ingredient_id' => $usageIngredientId,
                    'source_ingredient_id' => $sourceIngredientId,
                ]))
            ->groupBy('usage_ingredient_id')
            ->map(fn (Collection $rows): Collection => $rows->pluck('source_ingredient_id'));
        $allUsageIngredientIds = $sourceIngredientIdsByUsageIngredientId->keys();

        $ownedWorkspaceIds = $user->ownedWorkspaces()
            ->withoutGlobalScopes()
            ->pluck('workspaces.id')
            ->all();
        $recipeItems = RecipeItem::withoutGlobalScopes()
            ->join('recipe_versions', 'recipe_items.recipe_version_id', '=', 'recipe_versions.id')
            ->join('recipes', 'recipe_versions.recipe_id', '=', 'recipes.id')
            ->whereIn('recipe_items.ingredient_id', $allUsageIngredientIds)
            ->where('recipe_versions.is_current', true)
            ->where(fn (Builder $query): Builder => $this->whereAccessibleRecipe($query, $user, $ownedWorkspaceIds))
            ->get([
                'recipe_items.ingredient_id',
                'recipe_items.recipe_version_id as version_id',
                'recipe_versions.is_current as version_is_current',
                'recipes.id as recipe_id',
                'recipes.public_id as recipe_public_id',
                'recipes.name as recipe_name',
            ]);

        $usageRows = $this->usageRows($recipeItems, $sourceIngredientIdsByUsageIngredientId);

        return $usageRows
            ->groupBy('ingredient_id')
            ->map(fn (Collection $ingredientRows): array => $ingredientRows
                ->groupBy('recipe_id')
                ->map(function (Collection $recipeRows): array {
                    $firstRow = $recipeRows->first();

                    return [
                        'recipe_id' => $firstRow['recipe_id'],
                        'name' => $firstRow['recipe_name'],
                        'version_count' => 0,
                        'url' => route('recipes.edit', $firstRow['recipe_public_id']),
                    ];
                })
                ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
                ->values()
                ->all())
            ->all();
    }

    /**
     * @param  array<int>  $ownedWorkspaceIds
     */
    private function whereAccessibleRecipe(Builder $query, User $user, array $ownedWorkspaceIds): Builder
    {
        return $query->where(function (Builder $accessibleQuery) use ($ownedWorkspaceIds, $user): void {
            $accessibleQuery->where(function (Builder $ownedQuery) use ($user): void {
                $ownedQuery
                    ->where('recipes.owner_type', OwnerType::User->value)
                    ->where('recipes.owner_id', $user->id);
            });

            if ($ownedWorkspaceIds !== []) {
                $accessibleQuery
                    ->orWhere(function (Builder $workspaceOwnedQuery) use ($ownedWorkspaceIds): void {
                        $workspaceOwnedQuery
                            ->where('recipes.owner_type', OwnerType::Workspace->value)
                            ->whereIn('recipes.owner_id', $ownedWorkspaceIds);
                    })
                    ->orWhereIn('recipes.workspace_id', $ownedWorkspaceIds);
            }
        });
    }

    /**
     * @param  Collection<int, RecipeItem>  $usageItems
     * @param  Collection<int, Collection<int, int>>  $sourceIngredientIdsByUsageIngredientId
     * @return Collection<int, array{ingredient_id: int, recipe_id: int, recipe_public_id: string, recipe_name: string, version_id: int, version_is_current: bool}>
     */
    private function usageRows(Collection $usageItems, Collection $sourceIngredientIdsByUsageIngredientId): Collection
    {
        return $usageItems->flatMap(fn (RecipeItem $usageItem): Collection => $sourceIngredientIdsByUsageIngredientId
            ->get($usageItem->ingredient_id, collect())
            ->map(fn (int $sourceIngredientId): array => [
                'ingredient_id' => $sourceIngredientId,
                'recipe_id' => $usageItem->recipe_id,
                'recipe_public_id' => $usageItem->recipe_public_id,
                'recipe_name' => $usageItem->recipe_name,
                'version_id' => $usageItem->version_id,
                'version_is_current' => (bool) $usageItem->version_is_current,
            ]));
    }
}
