<?php

namespace App\Models;

use App\Enums\IngredientTranslationOrigin;
use Database\Factories\IngredientTranslationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'ingredient_id',
    'locale',
    'display_name',
    'saponification_name',
    'info_markdown',
    'source_fingerprint',
    'origin',
    'prompt_version',
])]
class IngredientTranslation extends Model
{
    /** @use HasFactory<IngredientTranslationFactory> */
    use HasFactory;

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    protected function casts(): array
    {
        return [
            'origin' => IngredientTranslationOrigin::class,
        ];
    }
}
