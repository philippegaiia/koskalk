<?php

namespace App\Services;

use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\RecipeVersionCosting;
use App\Models\RecipeVersionCostingPackagingItem;
use App\Models\RecipeVersionPackagingItem;
use App\Models\User;
use App\Models\UserPackagingItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class PackagingItemFormulaMutationService
{
    public function __construct(
        private readonly RetriableDatabaseTransaction $transaction,
    ) {}

    /**
     * @return array{
     *     formula_count: int,
     *     recipes: EloquentCollection<int, Recipe>,
     *     blocked_recipes: EloquentCollection<int, Recipe>,
     *     inaccessible_blocked_count: int
     * }
     */
    public function impact(User $user, UserPackagingItem $packagingItem): array
    {
        $affectedRecipes = Recipe::withoutGlobalScopes()
            ->whereHas('versions', function (Builder $versionQuery) use ($packagingItem): void {
                $versionQuery
                    ->withoutGlobalScopes()
                    ->where(function (Builder $usageQuery) use ($packagingItem): void {
                        $usageQuery
                            ->whereHas('packagingItems', fn (Builder $itemQuery): Builder => $itemQuery
                                ->where('user_packaging_item_id', $packagingItem->id))
                            ->orWhereHas('costings.packagingItems', fn (Builder $itemQuery): Builder => $itemQuery
                                ->where('user_packaging_item_id', $packagingItem->id));
                    });
            })
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        $editableRecipes = new EloquentCollection;
        $blockedRecipes = new EloquentCollection;
        $inaccessibleBlockedCount = 0;

        foreach ($affectedRecipes as $recipe) {
            if ($user->can('update', $recipe)) {
                $editableRecipes->add($recipe);
            } elseif ($user->can('view', $recipe)) {
                $blockedRecipes->add($recipe);
            } else {
                $inaccessibleBlockedCount++;
            }
        }

        return [
            'formula_count' => $affectedRecipes->count(),
            'recipes' => $editableRecipes,
            'blocked_recipes' => $blockedRecipes,
            'inaccessible_blocked_count' => $inaccessibleBlockedCount,
        ];
    }

    public function removeEverywhereAndDelete(User $user, UserPackagingItem $packagingItem): void
    {
        $this->transaction->run(function () use ($user, $packagingItem): void {
            $lockedPackagingItem = UserPackagingItem::query()
                ->whereKey($packagingItem->getKey())
                ->lockForUpdate()
                ->first();

            if (! $lockedPackagingItem instanceof UserPackagingItem || $lockedPackagingItem->user_id !== $user->id) {
                throw ValidationException::withMessages([
                    'packaging_item' => 'Only your own packaging items can be removed and deleted.',
                ]);
            }

            $impact = $this->impact($user, $lockedPackagingItem);

            if ($impact['blocked_recipes']->isNotEmpty() || $impact['inaccessible_blocked_count'] > 0) {
                throw ValidationException::withMessages([
                    'packaging_item' => 'Edit the formulas you cannot manage manually before deleting this packaging item.',
                ]);
            }

            $affectedVersionIds = RecipeVersionPackagingItem::query()
                ->whereBelongsTo($lockedPackagingItem, 'packagingItem')
                ->pluck('recipe_version_id')
                ->concat(
                    RecipeVersionCosting::query()
                        ->whereHas('packagingItems', fn (Builder $itemQuery): Builder => $itemQuery
                            ->where('user_packaging_item_id', $lockedPackagingItem->id))
                        ->pluck('recipe_version_id'),
                )
                ->map(fn (mixed $versionId): int => (int) $versionId)
                ->unique()
                ->sort()
                ->values();

            if ($affectedVersionIds->isNotEmpty()) {
                RecipeVersion::withoutGlobalScopes()
                    ->whereKey($affectedVersionIds->all())
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get(['id']);

                $costingItemIds = RecipeVersionCostingPackagingItem::query()
                    ->whereBelongsTo($lockedPackagingItem, 'packagingItem')
                    ->whereHas('costing', fn (Builder $costingQuery): Builder => $costingQuery
                        ->whereIn('recipe_version_id', $affectedVersionIds))
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->pluck('id');

                if ($costingItemIds->isNotEmpty()) {
                    RecipeVersionCostingPackagingItem::query()
                        ->whereKey($costingItemIds->all())
                        ->delete();
                }

                $packagingPlanItemIds = RecipeVersionPackagingItem::query()
                    ->whereBelongsTo($lockedPackagingItem, 'packagingItem')
                    ->whereIn('recipe_version_id', $affectedVersionIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->pluck('id');

                if ($packagingPlanItemIds->isNotEmpty()) {
                    RecipeVersionPackagingItem::query()
                        ->whereKey($packagingPlanItemIds->all())
                        ->delete();
                }
            }

            $featuredImagePath = $lockedPackagingItem->featured_image_path;

            DB::afterCommit(function () use ($featuredImagePath): void {
                try {
                    MediaStorage::deletePublicPath($featuredImagePath);
                } catch (Throwable $exception) {
                    report($exception);
                }
            });

            if ($lockedPackagingItem->delete() !== true) {
                throw new RuntimeException('The packaging item could not be deleted.');
            }
        });
    }
}
