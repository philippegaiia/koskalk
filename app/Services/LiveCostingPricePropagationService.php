<?php

namespace App\Services;

use App\Models\RecipeVersionCostingItem;
use App\Models\RecipeVersionCostingPackagingItem;
use App\Models\Workspace;

class LiveCostingPricePropagationService
{
    public function ingredientPriceChanged(Workspace $workspace, int $ingredientId, ?string $pricePerKg, ?int $exceptCostingId = null): void
    {
        $query = RecipeVersionCostingItem::query()
            ->where('ingredient_id', $ingredientId)
            ->whereHas('costing.recipeVersion', fn ($query) => $query->where('workspace_id', $workspace->id));

        if ($exceptCostingId !== null) {
            $query->where('recipe_version_costing_id', '!=', $exceptCostingId);
        }

        $query->update([
            'price_per_kg' => $pricePerKg === null ? null : round((float) $pricePerKg, 4),
            'updated_at' => now(),
        ]);
    }

    public function packagingPriceChanged(Workspace $workspace, int $packagingItemId, string $unitCost, ?int $exceptCostingId = null): void
    {
        $query = RecipeVersionCostingPackagingItem::query()
            ->where('packaging_item_id', $packagingItemId)
            ->whereHas('costing.recipeVersion', fn ($query) => $query->where('workspace_id', $workspace->id));

        if ($exceptCostingId !== null) {
            $query->where('recipe_version_costing_id', '!=', $exceptCostingId);
        }

        $query->update([
            'unit_cost' => round((float) $unitCost, 4),
            'updated_at' => now(),
        ]);
    }
}
