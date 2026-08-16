<?php

namespace App\Models;

use App\Enums\IngredientDuplicateResolution;
use App\Enums\IngredientIntakeItemStatus;
use App\Models\Concerns\HasPublicId;
use Database\Factories\IngredientIntakeItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'ingredient_intake_batch_id', 'row_number', 'original_current_name', 'normalized_current_name',
    'original_inci_name', 'normalized_inci_name', 'status', 'duplicate_candidates', 'duplicate_resolution',
    'existing_ingredient_id', 'promoted_ingredient_id', 'failure_code', 'failure_message',
    'promoted_by_user_id', 'promoted_at',
])]
class IngredientIntakeItem extends Model
{
    /** @use HasFactory<IngredientIntakeItemFactory> */
    use HasFactory;

    use HasPublicId;

    protected $attributes = [
        'status' => IngredientIntakeItemStatus::Draft->value,
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(IngredientIntakeBatch::class, 'ingredient_intake_batch_id');
    }

    public function existingIngredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class, 'existing_ingredient_id')->withoutGlobalScopes();
    }

    public function promotedIngredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class, 'promoted_ingredient_id')->withoutGlobalScopes();
    }

    public function enrichmentItems(): HasMany
    {
        return $this->hasMany(IngredientEnrichmentBatchItem::class, 'ingredient_intake_item_id');
    }

    public function promotedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'promoted_by_user_id');
    }

    protected function casts(): array
    {
        return [
            'status' => IngredientIntakeItemStatus::class,
            'duplicate_candidates' => 'array',
            'duplicate_resolution' => IngredientDuplicateResolution::class,
            'row_number' => 'integer',
            'promoted_at' => 'immutable_datetime',
        ];
    }
}
