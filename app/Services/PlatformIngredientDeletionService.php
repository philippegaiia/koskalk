<?php

namespace App\Services;

use App\Models\CurrentMaterialPrice;
use App\Models\Ingredient;
use App\Models\IngredientComponent;
use App\Models\IngredientEnrichmentBatchItem;
use App\Models\IngredientIntakeItem;
use App\Models\ProductionBatchIngredient;
use App\Models\RecipeItem;
use App\Models\RecipeVersionCostingItem;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class PlatformIngredientDeletionService
{
    public function __construct(
        private readonly RetriableDatabaseTransaction $transaction,
    ) {}

    public function delete(User $actor, Ingredient $ingredient): void
    {
        if (! $actor->is_admin) {
            throw new AuthorizationException('Only administrators may delete platform ingredients.');
        }

        $this->transaction->run(function () use ($ingredient): void {
            $lockedIngredient = Ingredient::query()
                ->whereKey($ingredient->getKey())
                ->lockForUpdate()
                ->first();

            if (! $lockedIngredient instanceof Ingredient) {
                throw ValidationException::withMessages([
                    'ingredient' => 'The platform ingredient is no longer available.',
                ]);
            }

            if ($lockedIngredient->owner_type !== null || $lockedIngredient->owner_id !== null) {
                throw ValidationException::withMessages([
                    'ingredient' => 'Only platform ingredients can be deleted from the catalog administrator.',
                ]);
            }

            $dependencies = $this->dependencyCounts($lockedIngredient);

            if (array_sum($dependencies) > 0) {
                throw ValidationException::withMessages([
                    'ingredient' => $this->blockedDeletionMessage($dependencies),
                ]);
            }

            $featuredImagePath = $lockedIngredient->featured_image_path;
            $iconImagePath = $lockedIngredient->icon_image_path;

            if ($lockedIngredient->delete() !== true) {
                throw new RuntimeException('The platform ingredient could not be deleted.');
            }

            DB::afterCommit(function () use ($featuredImagePath, $iconImagePath): void {
                try {
                    MediaStorage::deletePublicPath($featuredImagePath);
                    MediaStorage::deletePublicPath($iconImagePath);
                } catch (Throwable $exception) {
                    report($exception);
                }
            });
        });
    }

    /**
     * @return array{
     *     formula_items: int,
     *     costing_items: int,
     *     composite_ingredients: int,
     *     current_prices: int,
     *     production_batch_ingredients: int,
     *     enrichment_audit: int,
     *     intake_audit: int
     * }
     */
    private function dependencyCounts(Ingredient $ingredient): array
    {
        return [
            'formula_items' => RecipeItem::withoutGlobalScopes()->whereBelongsTo($ingredient)->count(),
            'costing_items' => RecipeVersionCostingItem::query()->whereBelongsTo($ingredient)->count(),
            'composite_ingredients' => IngredientComponent::query()
                ->where('component_ingredient_id', $ingredient->id)
                ->count(),
            'current_prices' => CurrentMaterialPrice::query()->whereBelongsTo($ingredient)->count(),
            'production_batch_ingredients' => ProductionBatchIngredient::query()->whereBelongsTo($ingredient)->count(),
            'enrichment_audit' => IngredientEnrichmentBatchItem::query()->where('ingredient_id', $ingredient->id)->count(),
            'intake_audit' => IngredientIntakeItem::query()
                ->where(fn ($query) => $query
                    ->where('existing_ingredient_id', $ingredient->id)
                    ->orWhere('promoted_ingredient_id', $ingredient->id))
                ->count(),
        ];
    }

    /**
     * @param  array{
     *     formula_items: int,
     *     costing_items: int,
     *     composite_ingredients: int,
     *     current_prices: int,
     *     production_batch_ingredients: int,
     *     enrichment_audit: int,
     *     intake_audit: int
     * }  $dependencies
     */
    private function blockedDeletionMessage(array $dependencies): string
    {
        $labels = [
            'formula_items' => 'formula item|formula items',
            'costing_items' => 'costing item|costing items',
            'composite_ingredients' => 'composite ingredient|composite ingredients',
            'current_prices' => 'current workspace price|current workspace prices',
            'production_batch_ingredients' => 'production batch ingredient|production batch ingredients',
            'enrichment_audit' => __('ingredient_admin.delete.dependency_labels.enrichment_audit'),
            'intake_audit' => __('ingredient_admin.delete.dependency_labels.intake_audit'),
        ];
        $usages = [];

        foreach ($dependencies as $dependency => $count) {
            if ($count === 0) {
                continue;
            }

            $usages[] = trans_choice($labels[$dependency], $count, ['count' => $count]).' ('.$count.')';
        }

        return 'This platform ingredient cannot be deleted because it is used by '.implode(', ', $usages).'. Deactivate it instead so existing formulas remain intact.';
    }
}
