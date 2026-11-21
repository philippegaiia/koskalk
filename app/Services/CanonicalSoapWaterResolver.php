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
 * finds it. The water category and subcategory remain a fallback for catalogs
 * that predate catalog keys. Only active platform rows qualify, never a
 * workspace duplicate, and tenant scopes never hide the platform row.
 */
final class CanonicalSoapWaterResolver
{
    public const string CATALOG_KEY = 'CH2';

    public function resolve(): ?Ingredient
    {
        return $this->platformIngredients()
            ->where('catalog_key', self::CATALOG_KEY)
            ->first()
            ?? $this->platformIngredients()
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
            ->where('is_active', true)
            ->whereNull('owner_type')
            ->whereNull('owner_id')
            ->whereNull('workspace_id');
    }
}
