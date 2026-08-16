<?php

namespace App\Models;

use App\Enums\IngredientIdentifierScheme;
use Database\Factories\IngredientIdentifierFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'ingredient_id',
    'scheme',
    'value',
    'normalized_value',
    'is_primary',
])]
class IngredientIdentifier extends Model
{
    /** @use HasFactory<IngredientIdentifierFactory> */
    use HasFactory;

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(IngredientIdentifierEvidence::class);
    }

    protected function casts(): array
    {
        return [
            'scheme' => IngredientIdentifierScheme::class,
            'is_primary' => 'boolean',
        ];
    }
}
