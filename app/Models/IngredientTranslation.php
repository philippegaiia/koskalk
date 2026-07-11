<?php

namespace App\Models;

use Database\Factories\IngredientTranslationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'ingredient_id',
    'locale',
    'display_name',
    'info_markdown',
])]
class IngredientTranslation extends Model
{
    /** @use HasFactory<IngredientTranslationFactory> */
    use HasFactory;

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }
}
