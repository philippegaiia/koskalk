<?php

namespace App\Models;

use Database\Factories\IngredientVersionFattyAcidFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'ingredient_version_id',
    'fatty_acid_id',
    'percentage',
    'source_notes',
    'source_data',
])]
class IngredientVersionFattyAcid extends Model
{
    /** @use HasFactory<IngredientVersionFattyAcidFactory> */
    use HasFactory;

    public function ingredientVersion(): BelongsTo
    {
        return $this->belongsTo(IngredientVersion::class);
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
