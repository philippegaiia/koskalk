<?php

namespace App\Models;

use App\Enums\IngredientEnrichmentItemStatus;
use App\Models\Concerns\HasPublicId;
use Database\Factories\IngredientEnrichmentBatchItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'ingredient_enrichment_batch_id',
    'ingredient_id',
    'catalog_key',
    'status',
    'snapshot',
    'source_fingerprint',
    'result',
    'validation_report',
    'plan',
    'replacement_fields',
    'confidence',
    'warnings',
    'unresolved_questions',
    'sources',
    'provider_response_id',
    'provider_request_id',
    'provider_model',
    'input_tokens',
    'output_tokens',
    'web_search_calls',
    'failure_code',
    'failure_message',
    'attempt_count',
    'approved_by_user_id',
    'applied_by_user_id',
    'research_started_at',
    'research_completed_at',
    'approved_at',
    'applied_at',
])]
class IngredientEnrichmentBatchItem extends Model
{
    /** @use HasFactory<IngredientEnrichmentBatchItemFactory> */
    use HasFactory;

    use HasPublicId;

    protected $attributes = [
        'status' => IngredientEnrichmentItemStatus::Pending->value,
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(IngredientEnrichmentBatch::class, 'ingredient_enrichment_batch_id');
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class)->withoutGlobalScopes();
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by_user_id');
    }

    protected function casts(): array
    {
        return [
            'status' => IngredientEnrichmentItemStatus::class,
            'snapshot' => 'array',
            'result' => 'array',
            'validation_report' => 'array',
            'plan' => 'array',
            'replacement_fields' => 'array',
            'warnings' => 'array',
            'unresolved_questions' => 'array',
            'sources' => 'array',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'web_search_calls' => 'integer',
            'attempt_count' => 'integer',
            'research_started_at' => 'immutable_datetime',
            'research_completed_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'applied_at' => 'immutable_datetime',
        ];
    }
}
