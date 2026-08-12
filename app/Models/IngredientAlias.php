<?php

namespace App\Models;

use App\Enums\IngredientAliasKind;
use Database\Factories\IngredientAliasFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'ingredient_id',
    'locale',
    'name',
    'normalized_name',
    'kind',
])]
class IngredientAlias extends Model
{
    /** @use HasFactory<IngredientAliasFactory> */
    use HasFactory;

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    protected function casts(): array
    {
        return [
            'kind' => IngredientAliasKind::class,
        ];
    }
}
