<?php

namespace App\Models;

use App\Enums\IngredientEvidenceConfidence;
use App\Enums\IngredientLabelMarket;
use App\Enums\IngredientSourceTier;
use Database\Factories\IngredientMarketLabelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'ingredient_id',
    'market_code',
    'declaration_name',
    'source_name',
    'source_url',
    'source_tier',
    'confidence',
    'source_version',
    'source_updated_at',
    'retrieved_at',
    'effective_from',
    'effective_until',
    'reviewed_at',
    'reviewed_by_user_id',
])]
class IngredientMarketLabel extends Model
{
    /** @use HasFactory<IngredientMarketLabelFactory> */
    use HasFactory;

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    protected function casts(): array
    {
        return [
            'market_code' => IngredientLabelMarket::class,
            'source_tier' => IngredientSourceTier::class,
            'confidence' => IngredientEvidenceConfidence::class,
            'effective_from' => 'date',
            'effective_until' => 'date',
            'source_updated_at' => 'immutable_date',
            'retrieved_at' => 'immutable_datetime',
            'reviewed_at' => 'datetime',
        ];
    }
}
