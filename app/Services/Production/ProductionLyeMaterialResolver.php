<?php

namespace App\Services\Production;

use App\Enums\ProductionFormulaComponent;
use App\Models\Ingredient;
use Illuminate\Validation\ValidationException;

class ProductionLyeMaterialResolver
{
    public function resolve(ProductionFormulaComponent $component): ?Ingredient
    {
        $catalogKey = match ($component) {
            ProductionFormulaComponent::Naoh => 'CH1',
            ProductionFormulaComponent::Koh => 'CH3',
            default => null,
        };

        if ($catalogKey === null) {
            return null;
        }

        $ingredient = Ingredient::query()
            ->withoutGlobalScopes()
            ->whereNull('workspace_id')
            ->where('catalog_key', $catalogKey)
            ->first();

        if (! $ingredient instanceof Ingredient) {
            throw ValidationException::withMessages([
                'recipe' => __('production_bench.production.validation.lye_material_missing', [
                    'key' => $catalogKey,
                ]),
            ]);
        }

        return $ingredient;
    }
}
