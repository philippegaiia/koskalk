<?php

namespace App\Models;

use Database\Factories\IngredientAllergenEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'ingredient_version_id',
    'allergen_id',
    'concentration_percent',
    'source_notes',
    'source_data',
])]
class IngredientAllergenEntry extends Model
{
    /** @use HasFactory<IngredientAllergenEntryFactory> */
    use HasFactory;

    public function ingredientVersion(): BelongsTo
    {
        return $this->belongsTo(IngredientVersion::class);
    }

    public function allergen(): BelongsTo
    {
        return $this->belongsTo(Allergen::class);
    }

    protected function casts(): array
    {
        return [
            'concentration_percent' => 'decimal:5',
            'source_data' => 'array',
        ];
    }
}
