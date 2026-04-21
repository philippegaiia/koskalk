<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

#[Fillable([
    'user_id',
    'ingredient_id',
    'price_per_kg',
    'currency',
    'last_used_at',
])]
/**
 * Remembers the latest commercial price a user wants to reuse for an ingredient.
 *
 * Each user keeps their own private price per ingredient, including shared catalog
 * ingredients. This avoids duplicating ingredient records just to store a buying price.
 * When a user enters a price in any costing tab, this table is upserted so the price
 * can be prefilled the next time they cost a formula containing that ingredient.
 *
 * @property int $id
 * @property int $user_id
 * @property int $ingredient_id
 * @property string|null $price_per_kg
 * @property string $currency
 * @property Carbon|null $last_used_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read Ingredient $ingredient
 */
class UserIngredientPrice extends Model
{
    /** The user who owns this price memory. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The ingredient this price applies to. */
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
