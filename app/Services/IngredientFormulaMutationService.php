<?php

namespace App\Services;

use App\IngredientCategory;
use App\Models\Ingredient;
use App\Models\IngredientComponent;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\RecipeVersion;
use App\Models\RecipeVersionCosting;
use App\Models\RecipeVersionCostingItem;
use App\Models\User;
use App\Models\UserIngredientPrice;
use App\Visibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class IngredientFormulaMutationService
{
    public function __construct(
        private readonly IngredientCompositeDependencyService $compositeDependencyService,
        private readonly RetriableDatabaseTransaction $transaction,
    ) {}

    /**
     * @return array{
     *     formula_count: int,
     *     recipes: EloquentCollection<int, Recipe>,
     *     editable_recipes: EloquentCollection<int, Recipe>,
     *     blocked_recipes: EloquentCollection<int, Recipe>,
     *     inaccessible_blocked_count: int,
     *     composite_count: int,
     *     editable_composites: EloquentCollection<int, Ingredient>,
     *     blocked_composites: EloquentCollection<int, Ingredient>,
     *     inaccessible_blocked_composite_count: int,
     *     requires_soap_carrier: bool
     * }
     */
    public function impact(User $user, Ingredient $ingredient): array
    {
        $dependencyGraph = $this->compositeDependencyService->forSource($ingredient->id);
        $affectedRecipes = $this->affectedRecipes($dependencyGraph['ingredient_ids']);
        $editableRecipes = new EloquentCollection;
        $blockedRecipes = new EloquentCollection;
        $inaccessibleBlockedCount = 0;
        $updateDecisions = [];
        $viewDecisions = [];
        $translationLocales = Ingredient::translationLocaleCandidates();
        $ancestorRelations = [];

        if ($translationLocales !== []) {
            $ancestorRelations['translations'] = fn (Builder|Relation $query): Builder|Relation => $query
                ->whereIn('locale', $translationLocales);
        }

        $ancestorComposites = Ingredient::query()
            ->with($ancestorRelations)
            ->whereKey($dependencyGraph['ancestor_ids']->all())
            ->orderBy('display_name')
            ->orderBy('id')
            ->get();
        $editableComposites = new EloquentCollection;
        $blockedComposites = new EloquentCollection;
        $inaccessibleBlockedCompositeCount = 0;

        foreach ($ancestorComposites as $ancestorComposite) {
            if ($this->isPrivateIngredientOwnedBy($ancestorComposite, $user)) {
                $editableComposites->add($ancestorComposite);
            } elseif ($ancestorComposite->isAccessibleBy($user)) {
                $blockedComposites->add($ancestorComposite);
            } else {
                $inaccessibleBlockedCompositeCount++;
            }
        }

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
            'composite_count' => $ancestorComposites->count(),
            'editable_composites' => $editableComposites,
            'blocked_composites' => $blockedComposites,
            'inaccessible_blocked_composite_count' => $inaccessibleBlockedCompositeCount,
            'requires_soap_carrier' => $this->requiresSoapCarrier($ingredient, $dependencyGraph['ingredient_ids']),
        ];
    }

    /** @return EloquentCollection<int, Ingredient> */
    public function replacementCandidates(User $user, Ingredient $ingredient): EloquentCollection
    {
        $translationLocales = Ingredient::translationLocaleCandidates();
        $relations = ['sapProfile'];

        if ($translationLocales !== []) {
            $relations['translations'] = fn (Builder|Relation $query): Builder|Relation => $query
                ->whereIn('locale', $translationLocales);
        }

        $query = Ingredient::query()
            ->with($relations)
            ->whereKeyNot($ingredient->id)
            ->where('is_active', true)
            ->accessibleTo($user);

        $dependencyGraph = $this->compositeDependencyService->forSource($ingredient->id);
        $ancestorIds = $dependencyGraph['ancestor_ids'];

        if ($ancestorIds->isNotEmpty()) {
            $query->whereNotIn($ingredient->getQualifiedKeyName(), $ancestorIds);
        }

        if ($ingredient->category !== null && in_array($ingredient->category, IngredientCategory::aromaticCases(), true)) {
            $query->whereIn('category', IngredientCategory::aromaticValues());
        } else {
            $query->where('category', $ingredient->category?->value);
        }

        $candidates = $query
            ->orderBy('display_name')
            ->orderBy('id')
            ->get();

        if (
            $ingredient->category !== IngredientCategory::CarrierOil
            || ! $this->requiresSoapCarrier($ingredient, $dependencyGraph['ingredient_ids'])
        ) {
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
        $this->transaction->run(function () use ($user, $ingredient, $replacement): void {
            $lockedIngredients = Ingredient::query()
                ->whereKey([$ingredient->getKey(), $replacement->getKey()])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $lockedIngredient = $this->validatedOwnedIngredient(
                $lockedIngredients->get($ingredient->getKey()),
                $user,
            );

            $lockedReplacement = $lockedIngredients->get($replacement->getKey());

            if (! $lockedReplacement instanceof Ingredient) {
                throw ValidationException::withMessages([
                    'replacementIngredientId' => 'The selected replacement ingredient is no longer available.',
                ]);
            }

            $dependencyGraph = $this->lockAndValidateCompositeGraph($lockedIngredient, $user);
            $impact = $this->impact($user, $lockedIngredient);
            $this->assertEveryAffectedFormulaIsEditable($impact);
            $this->assertEveryAffectedCompositeIsEditable($impact);

            if (! $this->isValidReplacement($user, $lockedIngredient, $lockedReplacement, $impact['requires_soap_carrier'])) {
                throw ValidationException::withMessages([
                    'replacementIngredientId' => 'Select an active, accessible ingredient that is compatible with every affected formula.',
                ]);
            }

            if ($dependencyGraph['ancestor_ids']->contains($lockedReplacement->id)) {
                throw ValidationException::withMessages([
                    'replacementIngredientId' => 'Select a replacement that does not create a composite ingredient cycle.',
                ]);
            }

            $affectedVersionIds = $this->affectedVersionIds($dependencyGraph['ingredient_ids']);

            IngredientComponent::query()
                ->where('component_ingredient_id', $lockedIngredient->id)
                ->update(['component_ingredient_id' => $lockedReplacement->id]);

            if ($affectedVersionIds->isNotEmpty()) {
                $this->lockAffectedVersions($affectedVersionIds);

                RecipeItem::withoutGlobalScopes()
                    ->whereIn('recipe_version_id', $affectedVersionIds)
                    ->whereBelongsTo($lockedIngredient)
                    ->update(['ingredient_id' => $lockedReplacement->id]);

                $this->replaceCostingItems(
                    $affectedVersionIds,
                    $lockedIngredient,
                    $lockedReplacement,
                );

                $this->invalidateGeneratedIngredientLists($affectedVersionIds);
            }

            $this->scheduleMediaDeletionAfterCommit($lockedIngredient);
            $this->deleteIngredient($lockedIngredient);
        });
    }

    public function removeEverywhereAndDelete(User $user, Ingredient $ingredient): void
    {
        $this->transaction->run(function () use ($user, $ingredient): void {
            $lockedIngredient = $this->validatedOwnedIngredient(
                Ingredient::query()
                    ->whereKey($ingredient->getKey())
                    ->lockForUpdate()
                    ->first(),
                $user,
            );

            $dependencyGraph = $this->lockAndValidateCompositeGraph($lockedIngredient, $user);
            $impact = $this->impact($user, $lockedIngredient);
            $this->assertEveryAffectedFormulaIsEditable($impact);
            $this->assertEveryAffectedCompositeIsEditable($impact);

            $affectedVersionIds = $this->affectedVersionIds($dependencyGraph['ingredient_ids']);

            if ($affectedVersionIds->isNotEmpty()) {
                $this->lockAffectedVersions($affectedVersionIds);
                $this->deleteCostingItems($affectedVersionIds, $lockedIngredient);
                $this->deleteRecipeItems($affectedVersionIds, $lockedIngredient);
                $this->invalidateGeneratedIngredientLists($affectedVersionIds);
            }

            IngredientComponent::query()
                ->where('component_ingredient_id', $lockedIngredient->id)
                ->delete();

            $this->scheduleMediaDeletionAfterCommit($lockedIngredient);
            $this->deleteIngredient($lockedIngredient);
        });
    }

    /**
     * @param  Collection<int, int>  $ingredientIds
     * @return EloquentCollection<int, Recipe>
     */
    private function affectedRecipes(Collection $ingredientIds): EloquentCollection
    {
        return Recipe::withoutGlobalScopes()
            ->whereHas('versions', function (Builder $versionQuery) use ($ingredientIds): void {
                $versionQuery
                    ->withoutGlobalScopes()
                    ->where(function (Builder $usageQuery) use ($ingredientIds): void {
                        $usageQuery
                            ->whereHas('items', fn (Builder $itemQuery): Builder => $itemQuery
                                ->withoutGlobalScopes()
                                ->whereIn('ingredient_id', $ingredientIds))
                            ->orWhereHas('costings.items', fn (Builder $costingItemQuery): Builder => $costingItemQuery
                                ->whereIn('ingredient_id', $ingredientIds));
                    });
            })
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    /** @param Collection<int, int> $ingredientIds */
    private function requiresSoapCarrier(Ingredient $ingredient, Collection $ingredientIds): bool
    {
        return $ingredient->category === IngredientCategory::CarrierOil
            && RecipeItem::withoutGlobalScopes()
                ->whereIn('ingredient_id', $ingredientIds)
                ->whereHas('recipePhase', fn (Builder $phaseQuery): Builder => $phaseQuery
                    ->withoutGlobalScopes()
                    ->where('slug', 'saponified_oils'))
                ->exists();
    }

    /**
     * @param  Collection<int, int>  $ingredientIds
     * @return Collection<int, int>
     */
    private function affectedVersionIds(Collection $ingredientIds): Collection
    {
        $directVersionIds = RecipeItem::withoutGlobalScopes()
            ->whereIn('ingredient_id', $ingredientIds)
            ->pluck('recipe_version_id');

        $costingVersionIds = RecipeVersionCosting::query()
            ->whereHas('items', fn (Builder $itemQuery): Builder => $itemQuery
                ->whereIn('ingredient_id', $ingredientIds))
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
        return $ingredient->isEditableBy($user)
            && $ingredient->visibility === Visibility::Private;
    }

    private function validatedOwnedIngredient(?Ingredient $ingredient, User $user): Ingredient
    {
        if (! $ingredient instanceof Ingredient || ! $this->isPrivateIngredientOwnedBy($ingredient, $user)) {
            throw ValidationException::withMessages([
                'ingredient' => 'Only your own private ingredients can be replaced or removed and deleted.',
            ]);
        }

        return $ingredient;
    }

    /**
     * @param  array{
     *     blocked_recipes: EloquentCollection<int, Recipe>,
     *     inaccessible_blocked_count: int
     * }  $impact
     */
    private function assertEveryAffectedFormulaIsEditable(array $impact): void
    {
        if ($impact['blocked_recipes']->isEmpty() && $impact['inaccessible_blocked_count'] === 0) {
            return;
        }

        throw ValidationException::withMessages([
            'ingredient' => $this->blockedFormulaMessage(
                $impact['blocked_recipes'],
                $impact['inaccessible_blocked_count'],
            ),
        ]);
    }

    /**
     * @param  array{
     *     blocked_composites: EloquentCollection<int, Ingredient>,
     *     inaccessible_blocked_composite_count: int
     * }  $impact
     */
    private function assertEveryAffectedCompositeIsEditable(array $impact): void
    {
        if ($impact['blocked_composites']->isEmpty() && $impact['inaccessible_blocked_composite_count'] === 0) {
            return;
        }

        $messages = ['Edit the affected composite ingredients manually before deleting this ingredient.'];

        if ($impact['blocked_composites']->isNotEmpty()) {
            $messages[] = 'You cannot edit: '.$impact['blocked_composites']->pluck('display_name')->implode(', ').'.';
        }

        if ($impact['inaccessible_blocked_composite_count'] > 0) {
            $messages[] = trans_choice(
                ':count additional composite ingredient cannot be edited.|:count additional composite ingredients cannot be edited.',
                $impact['inaccessible_blocked_composite_count'],
                ['count' => $impact['inaccessible_blocked_composite_count']],
            );
        }

        throw ValidationException::withMessages(['ingredient' => implode(' ', $messages)]);
    }

    /**
     * @return array{ingredient_ids: Collection<int, int>, ancestor_ids: Collection<int, int>}
     */
    private function lockAndValidateCompositeGraph(Ingredient $ingredient, User $user): array
    {
        $lockedAncestorIds = collect();

        do {
            $dependencyGraph = $this->compositeDependencyService->forSource($ingredient->id, lockForUpdate: true);
            $ancestorIdsToLock = $dependencyGraph['ancestor_ids']->diff($lockedAncestorIds)->values();

            if ($ancestorIdsToLock->isEmpty()) {
                return $dependencyGraph;
            }

            $ancestorComposites = Ingredient::query()
                ->whereKey($ancestorIdsToLock->all())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if (
                $ancestorComposites->count() !== $ancestorIdsToLock->count()
                || $ancestorComposites->contains(fn (Ingredient $ancestor): bool => ! $this->isPrivateIngredientOwnedBy($ancestor, $user))
            ) {
                throw ValidationException::withMessages([
                    'ingredient' => 'Edit the affected composite ingredients manually before deleting this ingredient.',
                ]);
            }

            $lockedAncestorIds = $lockedAncestorIds
                ->concat($ancestorComposites->modelKeys())
                ->unique()
                ->values();
        } while (true);
    }

    /** @param Collection<int, int> $affectedVersionIds */
    private function lockAffectedVersions(Collection $affectedVersionIds): void
    {
        RecipeVersion::withoutGlobalScopes()
            ->whereKey($affectedVersionIds->all())
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id']);
    }

    /** @param Collection<int, int> $affectedVersionIds */
    private function deleteCostingItems(Collection $affectedVersionIds, Ingredient $ingredient): void
    {
        $costingItemIds = RecipeVersionCostingItem::query()
            ->whereBelongsTo($ingredient)
            ->whereHas('costing', fn (Builder $costingQuery): Builder => $costingQuery
                ->whereIn('recipe_version_id', $affectedVersionIds))
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id');

        if ($costingItemIds->isNotEmpty()) {
            RecipeVersionCostingItem::query()
                ->whereKey($costingItemIds->all())
                ->delete();
        }
    }

    /** @param Collection<int, int> $affectedVersionIds */
    private function deleteRecipeItems(Collection $affectedVersionIds, Ingredient $ingredient): void
    {
        $recipeItemIds = RecipeItem::withoutGlobalScopes()
            ->whereIn('recipe_version_id', $affectedVersionIds)
            ->whereBelongsTo($ingredient)
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id');

        if ($recipeItemIds->isNotEmpty()) {
            RecipeItem::withoutGlobalScopes()
                ->whereKey($recipeItemIds->all())
                ->delete();
        }
    }

    /** @param Collection<int, int> $affectedVersionIds */
    private function invalidateGeneratedIngredientLists(Collection $affectedVersionIds): void
    {
        RecipeVersion::withoutGlobalScopes()
            ->whereKey($affectedVersionIds->all())
            ->update([
                'final_ingredient_list' => null,
                'final_ingredient_list_basis_hash' => null,
                'final_plain_ingredient_list' => null,
                'final_plain_ingredient_list_basis_hash' => null,
            ]);
    }

    private function scheduleMediaDeletionAfterCommit(Ingredient $ingredient): void
    {
        $featuredImagePath = $ingredient->featured_image_path;
        $iconImagePath = $ingredient->icon_image_path;

        DB::afterCommit(function () use ($ingredient, $featuredImagePath, $iconImagePath): void {
            try {
                MediaStorage::deleteIngredientPath($ingredient, $featuredImagePath);
                MediaStorage::deleteIngredientPath($ingredient, $iconImagePath);
                MediaStorage::deleteIngredientDirectory($ingredient);
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }

    private function deleteIngredient(Ingredient $ingredient): void
    {
        if ($ingredient->delete() !== true) {
            throw new RuntimeException('The ingredient could not be deleted.');
        }
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
                ':count additional formula cannot be edited.|:count additional formulas cannot be edited.',
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
