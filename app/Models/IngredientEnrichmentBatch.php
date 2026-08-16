<?php

namespace App\Models;

use App\Enums\IngredientEnrichmentBatchStatus;
use App\Models\Concerns\HasPublicId;
use Database\Factories\IngredientEnrichmentBatchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'requested_by_user_id',
    'status',
    'laravel_batch_id',
    'model',
    'reasoning_effort',
    'prompt_version',
    'schema_version',
    'mode',
    'total_count',
    'pending_count',
    'researching_count',
    'ready_count',
    'warning_count',
    'failed_count',
    'approved_count',
    'applied_count',
    'unchanged_count',
    'stale_count',
    'cancelled_count',
    'rejected_count',
    'input_tokens',
    'output_tokens',
    'web_search_calls',
    'structured_source_calls',
    'started_at',
    'completed_at',
    'cancelled_at',
])]
class IngredientEnrichmentBatch extends Model
{
    /** @use HasFactory<IngredientEnrichmentBatchFactory> */
    use HasFactory;

    use HasPublicId;

    protected $attributes = [
        'status' => IngredientEnrichmentBatchStatus::Pending->value,
        'mode' => 'fill_missing',
        'structured_source_calls' => 0,
    ];

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(IngredientEnrichmentBatchItem::class)->orderBy('id');
    }

    protected function casts(): array
    {
        return [
            'status' => IngredientEnrichmentBatchStatus::class,
            'schema_version' => 'integer',
            'total_count' => 'integer',
            'pending_count' => 'integer',
            'researching_count' => 'integer',
            'ready_count' => 'integer',
            'warning_count' => 'integer',
            'failed_count' => 'integer',
            'approved_count' => 'integer',
            'applied_count' => 'integer',
            'unchanged_count' => 'integer',
            'stale_count' => 'integer',
            'cancelled_count' => 'integer',
            'rejected_count' => 'integer',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'web_search_calls' => 'integer',
            'structured_source_calls' => 'integer',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }
}
