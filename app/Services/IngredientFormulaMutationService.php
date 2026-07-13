<?php

namespace App\Services;

use App\IngredientCategory;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\RecipeVersion;
use App\Models\RecipeVersionCosting;
use App\Models\RecipeVersionCostingItem;
use App\Models\User;
use App\Models\UserIngredientPrice;
use App\OwnerType;
use App\Visibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

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

    public function replaceEverywhereAndDelete(
        User $user,
        Ingredient $ingredient,
        Ingredient $replacement,
    ): void {
        DB::transaction(function () use ($user, $ingredient, $replacement): void {
            $lockedIngredients = Ingredient::query()
                ->whereKey([$ingredient->getKey(), $replacement->getKey()])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $lockedIngredient = $lockedIngredients->get($ingredient->getKey());

            if (! $lockedIngredient instanceof Ingredient || ! $this->isPrivateIngredientOwnedBy($lockedIngredient, $user)) {
                throw ValidationException::withMessages([
                    'ingredient' => 'Only your own private ingredients can be replaced and deleted.',
                ]);
            }

            $lockedReplacement = $lockedIngredients->get($replacement->getKey());

            if (! $lockedReplacement instanceof Ingredient) {
                throw ValidationException::withMessages([
                    'replacementIngredientId' => 'The selected replacement ingredient is no longer available.',
                ]);
            }

            $impact = $this->impact($user, $lockedIngredient);

            if ($impact['blocked_recipes']->isNotEmpty() || $impact['inaccessible_blocked_count'] > 0) {
                throw ValidationException::withMessages([
                    'ingredient' => $this->blockedFormulaMessage(
                        $impact['blocked_recipes'],
                        $impact['inaccessible_blocked_count'],
                    ),
                ]);
            }

            if (! $this->isValidReplacement($user, $lockedIngredient, $lockedReplacement, $impact['requires_soap_carrier'])) {
                throw ValidationException::withMessages([
                    'replacementIngredientId' => 'Select an active, accessible ingredient that is compatible with every affected formula.',
                ]);
            }

            $affectedVersionIds = $this->affectedVersionIds($lockedIngredient);

            if ($affectedVersionIds->isNotEmpty()) {
                RecipeVersion::withoutGlobalScopes()
                    ->whereKey($affectedVersionIds->all())
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get(['id']);

                RecipeItem::withoutGlobalScopes()
                    ->whereIn('recipe_version_id', $affectedVersionIds)
                    ->whereBelongsTo($lockedIngredient)
                    ->update(['ingredient_id' => $lockedReplacement->id]);

                $this->replaceCostingItems(
                    $affectedVersionIds,
                    $lockedIngredient,
                    $lockedReplacement,
                );

                RecipeVersion::withoutGlobalScopes()
                    ->whereKey($affectedVersionIds->all())
                    ->update([
                        'final_ingredient_list' => null,
                        'final_ingredient_list_basis_hash' => null,
                        'final_plain_ingredient_list' => null,
                        'final_plain_ingredient_list_basis_hash' => null,
                    ]);
            }

            $featuredImagePath = $lockedIngredient->featured_image_path;
            $iconImagePath = $lockedIngredient->icon_image_path;

            DB::afterCommit(function () use ($featuredImagePath, $iconImagePath): void {
                try {
                    MediaStorage::deletePublicPath($featuredImagePath);
                    MediaStorage::deletePublicPath($iconImagePath);
                } catch (Throwable $exception) {
                    report($exception);
                }
            });

            $lockedIngredient->delete();
        });
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

    /** @return Collection<int, int> */
    private function affectedVersionIds(Ingredient $ingredient): Collection
    {
        $directVersionIds = RecipeItem::withoutGlobalScopes()
            ->whereBelongsTo($ingredient)
            ->pluck('recipe_version_id');

        $costingVersionIds = RecipeVersionCosting::query()
            ->whereHas('items', fn (Builder $itemQuery): Builder => $itemQuery
                ->whereBelongsTo($ingredient))
            ->pluck('recipe_version_id');

        return $directVersionIds
            ->concat($costingVersionIds)
            ->map(fn (mixed $versionId): int => (int) $versionId)
            ->unique()
            ->sort()
            ->values();
    }

    private function isPrivateIngredientOwnedBy(Ingredient $ingredient, User $user): bool
    {
        return $ingredient->tenantOwnerType() === OwnerType::User
            && $ingredient->tenantOwnerId() === $user->id
            && $ingredient->visibility === Visibility::Private;
    }

    /**
     * @param  Collection<int, int>  $affectedVersionIds
     */
    private function replaceCostingItems(
        Collection $affectedVersionIds,
        Ingredient $ingredient,
        Ingredient $replacement,
    ): void {
        $costingItems = RecipeVersionCostingItem::query()
            ->with('costing:id,user_id')
            ->whereBelongsTo($ingredient)
            ->whereHas('costing', fn (Builder $costingQuery): Builder => $costingQuery
                ->whereIn('recipe_version_id', $affectedVersionIds))
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'recipe_version_costing_id']);

        if ($costingItems->isEmpty()) {
            return;
        }

        $costingOwnerIds = $costingItems
            ->pluck('costing.user_id')
            ->map(fn (mixed $userId): int => (int) $userId)
            ->unique()
            ->values();
        $replacementPricesByUserId = UserIngredientPrice::query()
            ->whereIn('user_id', $costingOwnerIds)
            ->whereBelongsTo($replacement)
            ->pluck('price_per_kg', 'user_id');

        $costingItems
            ->groupBy(fn (RecipeVersionCostingItem $costingItem): int => $costingItem->costing->user_id)
            ->each(function (EloquentCollection $ownerCostingItems, int $userId) use ($replacement, $replacementPricesByUserId): void {
                RecipeVersionCostingItem::query()
                    ->whereKey($ownerCostingItems->modelKeys())
                    ->update([
                        'ingredient_id' => $replacement->id,
                        'price_per_kg' => $replacementPricesByUserId->get($userId),
                    ]);
            });
    }

    private function isValidReplacement(
        User $user,
        Ingredient $ingredient,
        Ingredient $replacement,
        bool $requiresSoapCarrier,
    ): bool {
        if (
            $ingredient->is($replacement)
            || ! $replacement->is_active
            || ! $replacement->isAccessibleBy($user)
        ) {
            return false;
        }

        if ($ingredient->category !== null && in_array($ingredient->category, IngredientCategory::aromaticCases(), true)) {
            return $replacement->category !== null
                && in_array($replacement->category, IngredientCategory::aromaticCases(), true);
        }

        if ($ingredient->category !== $replacement->category) {
            return false;
        }

        if ($ingredient->category !== IngredientCategory::CarrierOil || ! $requiresSoapCarrier) {
            return true;
        }

        $replacement->loadMissing('sapProfile');

        return $replacement->canDriveSoapSaponification();
    }

    /**
     * @param  EloquentCollection<int, Recipe>  $blockedRecipes
     */
    private function blockedFormulaMessage(EloquentCollection $blockedRecipes, int $inaccessibleBlockedCount): string
    {
        $messages = [];

        if ($blockedRecipes->isNotEmpty()) {
            $messages[] = 'You cannot edit: '.$blockedRecipes->pluck('name')->implode(', ').'.';
        }

        if ($inaccessibleBlockedCount > 0) {
            $messages[] = trans_choice(
                ':count additional formula cannot be edited.',
                $inaccessibleBlockedCount,
                ['count' => $inaccessibleBlockedCount],
            );
        }

        return implode(' ', $messages);
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
