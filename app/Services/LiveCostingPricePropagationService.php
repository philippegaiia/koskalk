<?php

namespace App\Services;

use App\Models\RecipeVersionCostingItem;
use App\Models\RecipeVersionCostingPackagingItem;
use App\Models\User;

class LiveCostingPricePropagationService
{
    public function ingredientPriceChanged(User $user, int $ingredientId, float $pricePerKg, ?int $exceptCostingId = null): void
    {
        $query = RecipeVersionCostingItem::query()
            ->where('ingredient_id', $ingredientId)
            ->whereHas('costing', fn ($query) => $query->where('user_id', $user->id));

        if ($exceptCostingId !== null) {
            $query->where('recipe_version_costing_id', '!=', $exceptCostingId);
        }

        $query->update([
            'price_per_kg' => round($pricePerKg, 4),
            'updated_at' => now(),
        ]);
    }

    public function packagingUnitCostChanged(User $user, int $packagingItemId, float $unitCost, ?int $exceptCostingId = null): void
    {
        $query = RecipeVersionCostingPackagingItem::query()
            ->where('user_packaging_item_id', $packagingItemId)
            ->whereHas('costing', fn ($query) => $query->where('user_id', $user->id));

        if ($exceptCostingId !== null) {
            $query->where('recipe_version_costing_id', '!=', $exceptCostingId);
        }

        $query->update([
            'unit_cost' => round($unitCost, 4),
            'updated_at' => now(),
        ]);
    }
}
