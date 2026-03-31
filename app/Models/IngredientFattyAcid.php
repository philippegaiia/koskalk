<?php

namespace App\Models;

use Database\Factories\IngredientFattyAcidFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'ingredient_id',
    'fatty_acid_id',
    'percentage',
    'source_notes',
    'source_data',
])]
class IngredientFattyAcid extends Model
{
    /** @use HasFactory<IngredientFattyAcidFactory> */
    use HasFactory;

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function fattyAcid(): BelongsTo
    {
        return $this->belongsTo(FattyAcid::class);
    }

    protected function casts(): array
    {
        return [
            'percentage' => 'decimal:5',
            'source_data' => 'array',
        ];
    }
}
