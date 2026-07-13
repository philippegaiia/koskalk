<?php

namespace App\Services;

use App\IngredientCategory;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

class IngredientFormulaMutationService
{
    /**
     * @return array{
     *     formula_count: int,
     *     recipes: EloquentCollection<int, Recipe>,
     *     editable_recipes: EloquentCollection<int, Recipe>,
     *     blocked_recipes: EloquentCollection<int, Recipe>,
     *     inaccessible_blocked_count: int,
     *     requires_soap_carrier: bool
     * }
     */
    public function impact(User $user, Ingredient $ingredient): array
    {
        $affectedRecipes = $this->affectedRecipes($ingredient);
        $editableRecipes = new EloquentCollection;
        $blockedRecipes = new EloquentCollection;
        $inaccessibleBlockedCount = 0;
        $updateDecisions = [];
        $viewDecisions = [];

        foreach ($affectedRecipes as $recipe) {
            $updateContextKey = $this->updateAuthorizationContextKey($recipe);
            $canUpdate = $updateDecisions[$updateContextKey]
                ??= $user->can('update', $recipe);

            if ($canUpdate) {
                $editableRecipes->add($recipe);

                continue;
            }

            $viewContextKey = $this->viewAuthorizationContextKey($recipe);
            $canView = $viewDecisions[$viewContextKey]
                ??= $user->can('view', $recipe);

            if ($canView) {
                $blockedRecipes->add($recipe);

                continue;
            }

            $inaccessibleBlockedCount++;
        }

        return [
            'formula_count' => $affectedRecipes->count(),
            'recipes' => $editableRecipes,
            'editable_recipes' => $editableRecipes,
            'blocked_recipes' => $blockedRecipes,
            'inaccessible_blocked_count' => $inaccessibleBlockedCount,
            'requires_soap_carrier' => $this->requiresSoapCarrier($ingredient),
        ];
    }

    /** @return EloquentCollection<int, Ingredient> */
    public function replacementCandidates(User $user, Ingredient $ingredient): EloquentCollection
    {
        $query = Ingredient::query()
            ->with('sapProfile')
            ->whereKeyNot($ingredient->id)
            ->where('is_active', true)
            ->accessibleTo($user);

        if ($ingredient->category !== null && in_array($ingredient->category, IngredientCategory::aromaticCases(), true)) {
            $query->whereIn('category', IngredientCategory::aromaticValues());
        } else {
            $query->where('category', $ingredient->category?->value);
        }

        $candidates = $query
            ->orderBy('display_name')
            ->orderBy('id')
            ->get();

        if ($ingredient->category !== IngredientCategory::CarrierOil || ! $this->requiresSoapCarrier($ingredient)) {
            return $candidates;
        }

        return $candidates
            ->filter(fn (Ingredient $candidate): bool => $candidate->canDriveSoapSaponification())
            ->values();
    }

    /** @return EloquentCollection<int, Recipe> */
    private function affectedRecipes(Ingredient $ingredient): EloquentCollection
    {
        return Recipe::withoutGlobalScopes()
            ->whereHas('versions', function (Builder $versionQuery) use ($ingredient): void {
                $versionQuery
                    ->withoutGlobalScopes()
                    ->where(function (Builder $usageQuery) use ($ingredient): void {
                        $usageQuery
                            ->whereHas('items', fn (Builder $itemQuery): Builder => $itemQuery
                                ->withoutGlobalScopes()
                                ->whereBelongsTo($ingredient))
                            ->orWhereHas('costings.items', fn (Builder $costingItemQuery): Builder => $costingItemQuery
                                ->whereBelongsTo($ingredient));
                    });
            })
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    private function requiresSoapCarrier(Ingredient $ingredient): bool
    {
        return $ingredient->category === IngredientCategory::CarrierOil
            && RecipeItem::withoutGlobalScopes()
                ->whereBelongsTo($ingredient)
                ->whereHas('recipePhase', fn (Builder $phaseQuery): Builder => $phaseQuery
                    ->withoutGlobalScopes()
                    ->where('slug', 'saponified_oils'))
                ->exists();
    }

    private function updateAuthorizationContextKey(Recipe $recipe): string
    {
        return json_encode([
            'owner_type' => $recipe->tenantOwnerType()?->value,
            'owner_id' => $recipe->tenantOwnerId(),
            'workspace_id' => $recipe->tenantWorkspaceId(),
            'is_private' => $recipe->is_private,
            'created_by' => $recipe->created_by,
        ], JSON_THROW_ON_ERROR);
    }

    private function viewAuthorizationContextKey(Recipe $recipe): string
    {
        return json_encode([
            'owner_type' => $recipe->tenantOwnerType()?->value,
            'owner_id' => $recipe->tenantOwnerId(),
            'workspace_id' => $recipe->tenantWorkspaceId(),
        ], JSON_THROW_ON_ERROR);
    }
}
