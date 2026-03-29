<?php

namespace App\Models;

use Database\Factories\IngredientComponentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'ingredient_id',
    'component_ingredient_id',
    'percentage_in_parent',
    'sort_order',
    'source_notes',
    'source_data',
])]
class IngredientComponent extends Model
{
    /** @use HasFactory<IngredientComponentFactory> */
    use HasFactory;

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function componentIngredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class, 'component_ingredient_id');
    }

    protected function casts(): array
    {
        return [
            'percentage_in_parent' => 'decimal:5',
            'source_data' => 'array',
        ];
    }
}
