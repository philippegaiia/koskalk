<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id',
    'name',
    'unit_cost',
    'currency',
    'notes',
])]
/**
 * Stores reusable packaging items that can be pulled into a formula costing.
 */
class UserPackagingItem extends Model
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function costingItems(): HasMany
    {
        return $this->hasMany(RecipeVersionCostingPackagingItem::class);
    }

    protected function casts(): array
    {
        return [
            'unit_cost' => 'decimal:4',
        ];
    }
}
