<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

#[Fillable([
    'recipe_version_costing_id',
    'ingredient_id',
    'phase_key',
    'position',
    'price_per_kg',
])]
/**
 * Stores the price currently used for one formula row inside a costing session.
 *
 * Each row is identified by (ingredient_id, phase_key, position) and holds the
 * price_per_kg the user set for that specific ingredient in that specific costing.
 * This price is independent of the user's global default price, so changing the
 * default later does not silently rewrite existing formula costings.
 *
 * @property int $id
 * @property int $recipe_version_costing_id
 * @property int $ingredient_id
 * @property string $phase_key
 * @property int $position
 * @property string|null $price_per_kg
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read RecipeVersionCosting $costing
 * @property-read Ingredient $ingredient
 */
class RecipeVersionCostingItem extends Model
{
    /** The parent costing session this row belongs to. */
    public function costing(): BelongsTo
    {
        return $this->belongsTo(RecipeVersionCosting::class, 'recipe_version_costing_id');
    }

    /** The ingredient this costing row prices. */
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
