<?php

namespace App\Models;

use App\Enums\IngredientIntakeBatchStatus;
use App\Enums\IngredientIntakeInputMethod;
use App\Enums\IngredientResearchFamily;
use App\Models\Concerns\HasPublicId;
use Database\Factories\IngredientIntakeBatchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'created_by_user_id', 'status', 'name', 'notes', 'input_method', 'family_hint', 'allow_gap_research',
    'original_filename', 'storage_disk', 'storage_path', 'ingredient_enrichment_batch_id',
    'total_count', 'draft_count', 'needs_resolution_count', 'queued_count', 'researching_count',
    'ready_count', 'failed_count', 'approved_count', 'promoted_count', 'rejected_count',
    'started_at', 'completed_at', 'cancelled_at',
])]
class IngredientIntakeBatch extends Model
{
    /** @use HasFactory<IngredientIntakeBatchFactory> */
    use HasFactory;

    use HasPublicId;

    protected $attributes = [
        'status' => IngredientIntakeBatchStatus::Draft->value,
        'input_method' => IngredientIntakeInputMethod::Paste->value,
        'allow_gap_research' => false,
        'total_count' => 0,
        'draft_count' => 0,
        'needs_resolution_count' => 0,
        'queued_count' => 0,
        'researching_count' => 0,
        'ready_count' => 0,
        'failed_count' => 0,
        'approved_count' => 0,
        'promoted_count' => 0,
        'rejected_count' => 0,
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(IngredientIntakeItem::class)->orderBy('row_number')->orderBy('id');
    }

    public function enrichmentBatch(): BelongsTo
    {
        return $this->belongsTo(IngredientEnrichmentBatch::class, 'ingredient_enrichment_batch_id');
    }

    protected function casts(): array
    {
        return [
            'status' => IngredientIntakeBatchStatus::class,
            'input_method' => IngredientIntakeInputMethod::class,
            'family_hint' => IngredientResearchFamily::class,
            'allow_gap_research' => 'boolean',
            'total_count' => 'integer',
            'draft_count' => 'integer',
            'needs_resolution_count' => 'integer',
            'queued_count' => 'integer',
            'researching_count' => 'integer',
            'ready_count' => 'integer',
            'failed_count' => 'integer',
            'approved_count' => 'integer',
            'promoted_count' => 'integer',
            'rejected_count' => 'integer',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }
}
