<?php

namespace App\Services;

use App\Enums\IngredientCategory;
use App\Enums\IngredientSubcategory;
use App\Models\Ingredient;
use Illuminate\Database\Eloquent\Builder;

/**
 * Resolves the Koskalk-owned plain water ingredient that soap costing prices.
 *
 * Water is identified by its catalog key (CH2), like the canonical alkalis,
 * so a catalog whose taxonomy drifted (missing or edited subcategory) still
 * finds it. The water category and subcategory are a fallback only for a
 * catalog that has no CH2 row at all: a deactivated CH2 resolves to nothing
 * rather than silently pricing another water row. Only active platform rows
 * qualify, never a workspace duplicate.
 */
final class CanonicalSoapWaterResolver
{
    public const string CATALOG_KEY = 'CH2';

    public function resolve(): ?Ingredient
    {
        $canonical = $this->platformIngredients()
            ->where('catalog_key', self::CATALOG_KEY)
            ->first();

        if ($canonical instanceof Ingredient) {
            return $canonical->is_active ? $canonical : null;
        }

        return $this->platformIngredients()
            ->where('is_active', true)
            ->where('category', IngredientCategory::WaterSolventsCarriers->value)
            ->where('subcategory', IngredientSubcategory::Water->value)
            ->orderBy('id')
            ->first();
    }

    /** @return Builder<Ingredient> */
    private function platformIngredients(): Builder
    {
        return Ingredient::query()
            ->withoutGlobalScopes()
            ->whereNull('owner_type')
            ->whereNull('owner_id')
            ->whereNull('workspace_id');
    }
}
