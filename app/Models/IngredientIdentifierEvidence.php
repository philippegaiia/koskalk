<?php

namespace App\Models;

use App\Enums\IngredientEvidenceConfidence;
use App\Enums\IngredientSourceTier;
use Database\Factories\IngredientIdentifierEvidenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'ingredient_identifier_id',
    'source_name',
    'source_url',
    'source_tier',
    'confidence',
    'source_version',
    'source_updated_at',
    'retrieved_at',
])]
class IngredientIdentifierEvidence extends Model
{
    /** @use HasFactory<IngredientIdentifierEvidenceFactory> */
    use HasFactory;

    public function identifier(): BelongsTo
    {
        return $this->belongsTo(IngredientIdentifier::class, 'ingredient_identifier_id');
    }

    protected function casts(): array
    {
        return [
            'source_tier' => IngredientSourceTier::class,
            'confidence' => IngredientEvidenceConfidence::class,
            'source_updated_at' => 'immutable_date',
            'retrieved_at' => 'immutable_datetime',
        ];
    }
}
