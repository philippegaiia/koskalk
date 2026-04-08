<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'recipe_version_costing_id',
    'user_packaging_item_id',
    'name',
    'unit_cost',
    'quantity',
])]
/**
 * Captures packaging rows copied into a formula costing, independently from the catalog.
 */
class RecipeVersionCostingPackagingItem extends Model
{
    public function costing(): BelongsTo
    {
        return $this->belongsTo(RecipeVersionCosting::class, 'recipe_version_costing_id');
    }

    public function packagingItem(): BelongsTo
    {
        return $this->belongsTo(UserPackagingItem::class, 'user_packaging_item_id');
    }

    protected function casts(): array
    {
        return [
            'unit_cost' => 'decimal:4',
            'quantity' => 'decimal:3',
        ];
    }
}
