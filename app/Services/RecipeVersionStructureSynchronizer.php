<?php

namespace App\Services;

use App\Models\RecipeItem;
use App\Models\RecipePhase;
use App\Models\RecipeVersion;
use App\Models\RecipeVersionPackagingItem;
use App\Models\User;
use App\Models\UserPackagingItem;
use App\OwnerType;
use App\Visibility;

class RecipeVersionStructureSynchronizer
{
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
                'owner_type' => OwnerType::User,
                'owner_id' => $user->id,
                'workspace_id' => null,
                'visibility' => Visibility::Private,
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
                    'owner_type' => OwnerType::User,
                    'owner_id' => $user->id,
                    'workspace_id' => null,
                    'visibility' => Visibility::Private,
                    'position' => $itemIndex + 1,
                    'percentage' => $itemPayload['percentage'],
                    'weight' => $itemPayload['weight'],
                    'note' => $itemPayload['note'],
                ]);

                $recipeItem->recipeVersion()->associate($recipeVersion);
                $recipeItem->recipePhase()->associate($phase);
                $recipeItem->save();
            }
        }

        $this->syncPackagingItems($recipeVersion, $user, $normalizedPayload['packaging_items'] ?? []);
    }

    /**
     * @param  array<int, array<string, mixed>>  $packagingItems
     */
    private function syncPackagingItems(RecipeVersion $recipeVersion, User $user, array $packagingItems): void
    {
        RecipeVersionPackagingItem::query()
            ->where('recipe_version_id', $recipeVersion->id)
            ->delete();

        $linkedPackagingItems = UserPackagingItem::query()
            ->where('user_id', $user->id)
            ->whereIn('id', collect($packagingItems)
                ->pluck('user_packaging_item_id')
                ->filter(fn (mixed $id): bool => is_numeric($id))
                ->map(fn (mixed $id): int => (int) $id)
                ->all())
            ->get()
            ->keyBy('id');

        foreach ($packagingItems as $index => $packagingItemPayload) {
            $linkedPackagingItemId = isset($packagingItemPayload['user_packaging_item_id'])
                && $linkedPackagingItems->has((int) $packagingItemPayload['user_packaging_item_id'])
                    ? (int) $packagingItemPayload['user_packaging_item_id']
                    : null;

            $packagingItem = new RecipeVersionPackagingItem([
                'user_packaging_item_id' => $linkedPackagingItemId,
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
