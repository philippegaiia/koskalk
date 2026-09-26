<?php

namespace App\Services;

use App\Models\RecipeVersionCosting;
use App\Models\RecipeVersionCostingItem;
use App\Models\RecipeVersionCostingPackagingItem;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

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

        $this->propagate($workspace, $query, [
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

        $this->propagate($workspace, $query, [
            'unit_cost' => round((float) $unitCost, 4),
            'updated_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function propagate(Workspace $workspace, Builder $query, array $attributes): void
    {
        DB::transaction(function () use ($workspace, $query, $attributes): void {
            Workspace::withoutGlobalScopes()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            $column = array_key_exists('price_per_kg', $attributes) ? 'price_per_kg' : 'unit_cost';
            $price = $attributes[$column];
            if ($price === null) {
                $query->whereNotNull($column);
            } else {
                $query->where(fn (Builder $prices) => $prices->where($column, '!=', $price)->orWhereNull($column));
            }
            $costingIds = (clone $query)->distinct()->pluck('recipe_version_costing_id');
            $query->update($attributes);
            RecipeVersionCosting::query()->whereKey($costingIds)->increment('edit_revision');
        }, attempts: 5);
    }
}
