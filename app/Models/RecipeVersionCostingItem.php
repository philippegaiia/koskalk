<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'recipe_version_costing_id',
    'ingredient_id',
    'phase_key',
    'position',
    'price_per_kg',
])]
/**
 * Stores the price currently used for one formula row inside a costing session.
 */
class RecipeVersionCostingItem extends Model
{
    public function costing(): BelongsTo
    {
        return $this->belongsTo(RecipeVersionCosting::class, 'recipe_version_costing_id');
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'price_per_kg' => 'decimal:4',
        ];
    }
}
