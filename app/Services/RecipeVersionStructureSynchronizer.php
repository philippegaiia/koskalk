<?php

namespace App\Services;

use App\Enums\OwnerType;
use App\Models\PackagingItem;
use App\Models\ProductSize;
use App\Models\RecipeItem;
use App\Models\RecipePhase;
use App\Models\RecipeVersion;
use App\Models\RecipeVersionPackagingItem;
use App\Models\User;

class RecipeVersionStructureSynchronizer
{
    public function __construct(
        private readonly ProductSizeResolver $productSizeResolver,
        private readonly ProductSizeSynchronizer $productSizeSynchronizer,
    ) {}

    /**
     * @param  array<string, mixed>  $normalizedPayload
     */
    public function sync(RecipeVersion $recipeVersion, User $user, array $normalizedPayload): void
    {
        RecipeItem::withoutGlobalScopes()
            ->where('recipe_version_id', $recipeVersion->id)
            ->delete();

        RecipePhase::withoutGlobalScopes()
            ->where('recipe_version_id', $recipeVersion->id)
            ->delete();

        foreach ($normalizedPayload['phases'] as $phaseIndex => $phasePayload) {
            $phase = new RecipePhase([
                'owner_type' => OwnerType::Workspace,
                'owner_id' => $recipeVersion->workspace_id,
                'workspace_id' => $recipeVersion->workspace_id,
                'name' => $phasePayload['name'],
                'slug' => $phasePayload['key'],
                'phase_type' => $phasePayload['phase_type'],
                'sort_order' => $phaseIndex + 1,
                'is_system' => $phasePayload['is_system'],
            ]);

            $phase->recipeVersion()->associate($recipeVersion);
            $phase->save();

            foreach ($phasePayload['items'] as $itemIndex => $itemPayload) {
                $recipeItem = new RecipeItem([
                    'ingredient_id' => $itemPayload['ingredient_id'],
                    'owner_type' => OwnerType::Workspace,
                    'owner_id' => $recipeVersion->workspace_id,
                    'workspace_id' => $recipeVersion->workspace_id,
                    'position' => $itemIndex + 1,
                    'percentage' => $itemPayload['percentage'],
                    'weight' => $itemPayload['weight'],
                    'final_percentage' => $itemPayload['final_percentage'] ?? null,
                    'note' => $itemPayload['note'],
                ]);

                $recipeItem->recipeVersion()->associate($recipeVersion);
                $recipeItem->recipePhase()->associate($phase);
                $recipeItem->save();
            }
        }

        $recipeId = (int) $recipeVersion->recipe_id;
        $productSizeIdsByKey = is_array($normalizedPayload['product_sizes'] ?? null)
            ? $this->productSizeSynchronizer->sync($recipeId, $normalizedPayload['product_sizes'])
            : null;

        $this->syncPackagingItems($recipeVersion, $user, $normalizedPayload['packaging_items'] ?? [], $productSizeIdsByKey);
        $sizeIds = $productSizeIdsByKey === null
            ? ProductSize::query()->where('recipe_id', $recipeId)->whereNull('archived_at')->pluck('id')->all()
            : array_values($productSizeIdsByKey);
        if ($productSizeIdsByKey !== null) {
            $this->productSizeSynchronizer->forgetRemovedYields($recipeVersion, $sizeIds);
        }
        $packagedSizeIds = $recipeVersion->packagingItems()->whereNotNull('product_size_id')->pluck('product_size_id')->all();
        $recipeVersion->productSizes()->sync(array_values(array_unique([...$sizeIds, ...$packagedSizeIds])));
        $this->productSizeSynchronizer->pruneArchived($recipeId);
    }

    /**
     * Each line goes to its submitted size; a line without a known size goes to
     * the first submitted size, or to the Product's default size when the
     * payload carries no sizes.
     *
     * @param  array<int, array<string, mixed>>  $packagingItems
     * @param  array<string, int>|null  $productSizeIdsByKey
     */
    private function syncPackagingItems(RecipeVersion $recipeVersion, User $user, array $packagingItems, ?array $productSizeIdsByKey): void
    {
        RecipeVersionPackagingItem::query()
            ->where('recipe_version_id', $recipeVersion->id)
            ->delete();

        $linkedPackagingItems = PackagingItem::query()
            ->where('workspace_id', $recipeVersion->workspace_id)
            ->whereIn('id', collect($packagingItems)
                ->pluck('packaging_item_id')
                ->filter(fn (mixed $id): bool => is_numeric($id))
                ->map(fn (mixed $id): int => (int) $id)
                ->all())
            ->get()
            ->keyBy('id');

        $fallbackProductSizeId = $packagingItems === []
            ? null
            : (collect($productSizeIdsByKey ?? [])->first()
                ?? $this->productSizeResolver->defaultForProduct((int) $recipeVersion->recipe_id)->id);

        foreach ($packagingItems as $index => $packagingItemPayload) {
            $linkedPackagingItemId = isset($packagingItemPayload['packaging_item_id'])
                && $linkedPackagingItems->has((int) $packagingItemPayload['packaging_item_id'])
                    ? (int) $packagingItemPayload['packaging_item_id']
                    : null;

            $packagingItem = new RecipeVersionPackagingItem([
                'product_size_id' => $productSizeIdsByKey[$packagingItemPayload['product_size_key'] ?? ''] ?? $fallbackProductSizeId,
                'packaging_item_id' => $linkedPackagingItemId,
                'name' => trim((string) ($packagingItemPayload['name'] ?? '')),
                'components_per_unit' => (float) ($packagingItemPayload['components_per_unit'] ?? 1),
                'notes' => $packagingItemPayload['notes'] ?? null,
                'position' => isset($packagingItemPayload['position']) && is_numeric($packagingItemPayload['position'])
                    ? max(1, (int) $packagingItemPayload['position'])
                    : $index + 1,
            ]);

            $packagingItem->recipeVersion()->associate($recipeVersion);
            $packagingItem->save();
        }
    }
}
