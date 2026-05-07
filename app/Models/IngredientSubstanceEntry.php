<?php

namespace App\Models;

use Database\Factories\IngredientSubstanceEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'ingredient_id',
    'substance_id',
    'concentration_percent',
    'concentration_source',
    'source_notes',
    'source_data',
])]
class IngredientSubstanceEntry extends Model
{
    /** @use HasFactory<IngredientSubstanceEntryFactory> */
    use HasFactory;

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function substance(): BelongsTo
    {
        return $this->belongsTo(Substance::class);
    }

    protected function casts(): array
    {
        return [
            'concentration_percent' => 'decimal:5',
            'source_data' => 'array',
        ];
    }
}
