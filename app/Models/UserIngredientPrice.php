<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'ingredient_id',
    'price_per_kg',
    'currency',
    'last_used_at',
])]
/**
 * Remembers the latest commercial price a user wants to reuse for an ingredient.
 */
class UserIngredientPrice extends Model
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    protected function casts(): array
    {
        return [
            'price_per_kg' => 'decimal:4',
            'last_used_at' => 'datetime',
        ];
    }
}
