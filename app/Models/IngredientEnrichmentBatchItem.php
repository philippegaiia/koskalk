<?php

namespace App\Models;

use App\Enums\IngredientEnrichmentBatchMode;
use App\Enums\IngredientEnrichmentItemStatus;
use App\Enums\IngredientEnrichmentResearchStage;
use App\Models\Concerns\HasPublicId;
use Database\Factories\IngredientEnrichmentBatchItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'ingredient_enrichment_batch_id',
    'ingredient_id',
    'ingredient_intake_item_id',
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
    'research_stages',
    'original_result',
    'edited_fields',
    'edited_by_user_id',
    'edited_at',
    'provider_response_id',
    'provider_request_id',
    'provider_model',
    'input_tokens',
    'output_tokens',
    'web_search_calls',
    'structured_source_calls',
    'failure_code',
    'failure_message',
    'attempt_count',
    'approved_by_user_id',
    'applied_by_user_id',
    'research_started_at',
    'research_completed_at',
    'approved_at',
    'rejected_by_user_id',
    'rejected_at',
    'rejection_reason',
    'applied_at',
])]
class IngredientEnrichmentBatchItem extends Model
{
    /** @use HasFactory<IngredientEnrichmentBatchItemFactory> */
    use HasFactory;

    use HasPublicId;

    protected $attributes = [
        'status' => IngredientEnrichmentItemStatus::Pending->value,
        'research_stages' => '[]',
        'structured_source_calls' => 0,
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(IngredientEnrichmentBatch::class, 'ingredient_enrichment_batch_id');
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class)->withoutGlobalScopes();
    }

    public function intakeItem(): BelongsTo
    {
        return $this->belongsTo(IngredientIntakeItem::class, 'ingredient_intake_item_id');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by_user_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by_user_id');
    }

    public function editedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by_user_id');
    }

    public function retryableFromStage(?IngredientEnrichmentBatchMode $mode = null): ?IngredientEnrichmentResearchStage
    {
        $stages = is_array($this->research_stages) ? $this->research_stages : [];
        $effectiveMode = $mode;
        if ($effectiveMode === null) {
            $batchMode = $this->batch?->mode;
            $effectiveMode = $batchMode instanceof IngredientEnrichmentBatchMode ? $batchMode : null;
        }

        if ($this->failure_code === 'identity_unresolved'
            && ! ($effectiveMode?->isGuidance() ?? false)) {
            return IngredientEnrichmentResearchStage::IdentityPreparation;
        }

        $orderedStages = $effectiveMode?->guidanceStages() ?? [];
        if ($orderedStages === []) {
            $orderedStages = IngredientEnrichmentResearchStage::ordered();
        }

        foreach ($orderedStages as $stage) {
            $result = $stages[$stage->value] ?? null;
            if (! is_array($result) || ($result['status'] ?? null) !== 'completed') {
                return $stage;
            }

            if ($this->hasUnresolvedQuestions($result, $effectiveMode)) {
                return $stage;
            }
        }

        return $this->status === IngredientEnrichmentItemStatus::Failed
            ? ($effectiveMode?->isGuidance()
                ? $orderedStages[array_key_last($orderedStages)]
                : IngredientEnrichmentResearchStage::EuStructured)
            : null;
    }

    /** @param array<string, mixed> $stage */
    private function hasUnresolvedQuestions(array $stage, ?IngredientEnrichmentBatchMode $mode): bool
    {
        $outerQuestions = $stage['unresolved_questions'] ?? null;
        if (is_array($outerQuestions) && $outerQuestions !== []) {
            return true;
        }

        $batchMode = $mode ?? $this->batch?->mode;
        if (! $batchMode instanceof IngredientEnrichmentBatchMode || ! $batchMode->isGuidance()) {
            return false;
        }

        foreach ([
            data_get($stage, 'data.guidance.unresolved_questions'),
            data_get($stage, 'data.result.unresolved_questions'),
        ] as $questions) {
            if (is_array($questions) && $questions !== []) {
                return true;
            }
        }

        return false;
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
            'research_stages' => 'array',
            'original_result' => 'array',
            'edited_fields' => 'array',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'web_search_calls' => 'integer',
            'structured_source_calls' => 'integer',
            'attempt_count' => 'integer',
            'research_started_at' => 'immutable_datetime',
            'research_completed_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'rejected_at' => 'immutable_datetime',
            'applied_at' => 'immutable_datetime',
            'edited_at' => 'immutable_datetime',
        ];
    }
}
