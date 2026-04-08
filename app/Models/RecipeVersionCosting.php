<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'recipe_version_id',
    'user_id',
    'oil_weight_for_costing',
    'oil_unit_for_costing',
    'units_produced',
    'currency',
])]
/**
 * Holds the saved costing context for one user on one formula version.
 */
class RecipeVersionCosting extends Model
{
    public function recipeVersion(): BelongsTo
    {
        return $this->belongsTo(RecipeVersion::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(RecipeVersionCostingItem::class)->orderBy('phase_key')->orderBy('position');
    }

    public function packagingItems(): HasMany
    {
        return $this->hasMany(RecipeVersionCostingPackagingItem::class)->orderBy('id');
    }

    protected function casts(): array
    {
        return [
            'oil_weight_for_costing' => 'decimal:3',
            'units_produced' => 'integer',
        ];
    }
}
