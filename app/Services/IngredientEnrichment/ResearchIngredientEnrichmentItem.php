<?php

namespace App\Services\IngredientEnrichment;

use App\Data\IngredientEnrichmentPipelineResponse;
use App\Enums\IngredientEnrichmentItemStatus;
use App\Enums\IngredientIntakeItemStatus;
use App\Models\Ingredient;
use App\Models\IngredientEnrichmentBatchItem;
use App\Models\IngredientIntakeItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class ResearchIngredientEnrichmentItem
{
    public function __construct(
        private readonly IngredientEnrichmentPipeline $pipeline,
        private readonly IngredientEnrichmentSnapshotBuilder $snapshotBuilder,
        private readonly IngredientEnrichmentEvidenceVerifier $evidenceVerifier,
        private readonly IngredientEnrichmentResultValidator $validator,
        private readonly IngredientEnrichmentPlanner $planner,
        private readonly IngredientEnrichmentBatchService $batches,
        private readonly IngredientEnrichmentSubjectBuilder $subjectBuilder,
    ) {}

    public function handle(int $itemId, bool $allowGapResearch = false): void
    {
        $record = DB::transaction(function () use ($itemId): ?array {
            $item = IngredientEnrichmentBatchItem::query()->lockForUpdate()->find($itemId);
            if (! $item || ! in_array($item->status, [IngredientEnrichmentItemStatus::Pending, IngredientEnrichmentItemStatus::Failed], true)) {
                return null;
            }

            if ($item->ingredient_intake_item_id !== null) {
                $intakeItem = IngredientIntakeItem::query()
                    ->with(['batch', 'existingIngredient'])
                    ->lockForUpdate()
                    ->find($item->ingredient_intake_item_id);
                if (! $intakeItem || $this->subjectBuilder->forIntake($intakeItem)->fingerprint !== $item->source_fingerprint) {
                    $item->update(['status' => IngredientEnrichmentItemStatus::Stale]);
                    $this->batches->refresh($item->ingredient_enrichment_batch_id);

                    return null;
                }
                $item->update([
                    'status' => IngredientEnrichmentItemStatus::Researching,
                    'attempt_count' => $item->attempt_count + 1,
                    'research_started_at' => now(),
                    'failure_code' => null,
                    'failure_message' => null,
                ]);
                $intakeItem->update(['status' => IngredientIntakeItemStatus::Researching]);
                $this->batches->refreshIntake($intakeItem->ingredient_intake_batch_id);

                return $item->snapshot;
            }

            $ingredient = Ingredient::query()->withoutGlobalScopes()->lockForUpdate()->find($item->ingredient_id);
            if (! $ingredient || $ingredient->owner_type !== null || $ingredient->owner_id !== null
                || $this->snapshotBuilder->fingerprint($ingredient) !== $item->source_fingerprint) {
                $item->update(['status' => IngredientEnrichmentItemStatus::Stale]);
                $this->batches->refresh($item->ingredient_enrichment_batch_id);

                return null;
            }
            $item->update([
                'status' => IngredientEnrichmentItemStatus::Researching,
                'attempt_count' => $item->attempt_count + 1,
                'research_started_at' => now(),
                'failure_code' => null,
                'failure_message' => null,
            ]);

            return $item->snapshot;
        }, attempts: 5);

        if ($record === null) {
            return;
        }

        try {
            $response = $this->pipeline->run($itemId, $allowGapResearch);
            if (($response->result['identity_unresolved'] ?? false) === true) {
                $this->markIdentityUnresolved($itemId, $response->result);

                return;
            }
            if (IngredientEnrichmentBatchItem::query()->whereKey($itemId)->value('ingredient_intake_item_id') !== null) {
                $this->persistIntakeResult($itemId, $response);

                return;
            }
            DB::transaction(function () use ($itemId, $response): void {
                $item = IngredientEnrichmentBatchItem::query()->lockForUpdate()->findOrFail($itemId);
                $ingredient = Ingredient::query()->withoutGlobalScopes()->lockForUpdate()->find($item->ingredient_id);
                if (! $ingredient || $this->snapshotBuilder->fingerprint($ingredient) !== $item->source_fingerprint) {
                    $item->update(['status' => IngredientEnrichmentItemStatus::Stale]);
                    $this->batches->refresh($item->ingredient_enrichment_batch_id);

                    return;
                }
                $this->evidenceVerifier->verify($response->result, $response->sources);
                $report = $this->validator->validateOrFail($response->result, $ingredient);
                $normalized = $report['normalized'];
                $plan = $this->planner->plan($ingredient, $normalized);
                $warnings = collect($report['warnings'])->merge($normalized['warnings'])
                    ->merge($normalized['unresolved_questions'])->filter()->unique()->values()->all();
                $status = ! $plan['changed']
                    ? IngredientEnrichmentItemStatus::Unchanged
                    : ($warnings === [] ? IngredientEnrichmentItemStatus::Ready : IngredientEnrichmentItemStatus::Warning);
                $item->update([
                    'status' => $status,
                    'result' => $normalized,
                    'original_result' => $item->original_result ?? $normalized,
                    'validation_report' => $report,
                    'plan' => $plan,
                    'confidence' => $normalized['confidence'],
                    'warnings' => $warnings,
                    'unresolved_questions' => $normalized['unresolved_questions'],
                    'sources' => $response->sources,
                    'provider_response_id' => $response->providerResponseId,
                    'provider_request_id' => $response->providerRequestId,
                    'provider_model' => $response->providerModel,
                    'input_tokens' => $response->inputTokens,
                    'output_tokens' => $response->outputTokens,
                    'web_search_calls' => $response->webSearchCalls,
                    'structured_source_calls' => $response->structuredSourceCalls,
                    'research_completed_at' => now(),
                ]);
                $this->batches->refresh($item->ingredient_enrichment_batch_id);
            }, attempts: 5);
        } catch (Throwable $exception) {
            $this->markFailed($itemId, $exception);
            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function markIdentityUnresolved(int $itemId, array $result): void
    {
        DB::transaction(function () use ($itemId, $result): void {
            $item = IngredientEnrichmentBatchItem::query()->lockForUpdate()->findOrFail($itemId);
            $item->update([
                'status' => IngredientEnrichmentItemStatus::Failed,
                'failure_code' => 'identity_unresolved',
                'failure_message' => (string) __('ingredient_enrichment.warnings.identity_unresolved'),
                'result' => $result,
                'warnings' => collect($result['warnings'] ?? [])
                    ->merge($result['unresolved_questions'] ?? [])
                    ->filter(fn (mixed $warning): bool => is_string($warning) && trim($warning) !== '')
                    ->unique()
                    ->values()
                    ->all(),
                'unresolved_questions' => $result['unresolved_questions'] ?? [],
                'research_completed_at' => now(),
            ]);
            if ($item->ingredient_intake_item_id !== null) {
                $intakeItem = IngredientIntakeItem::query()
                    ->lockForUpdate()
                    ->find($item->ingredient_intake_item_id);
                if ($intakeItem) {
                    $intakeItem->update([
                        'status' => IngredientIntakeItemStatus::Failed,
                        'failure_code' => 'identity_unresolved',
                        'failure_message' => $item->failure_message,
                    ]);
                    $this->batches->refreshIntake($intakeItem->ingredient_intake_batch_id);
                }
            }
            $this->batches->refresh($item->ingredient_enrichment_batch_id);
        }, attempts: 5);
    }

    public function markFailed(int $itemId, Throwable $exception): void
    {
        DB::transaction(function () use ($itemId, $exception): void {
            $item = IngredientEnrichmentBatchItem::query()->lockForUpdate()->find($itemId);
            if (! $item || $item->status->isTerminal()) {
                return;
            }
            report($exception);
            $item->update([
                ...$this->failureTelemetry($item),
                'status' => IngredientEnrichmentItemStatus::Failed,
                'failure_code' => $exception instanceof IngredientResearchProviderException
                    ? $exception->failureCode
                    : class_basename($exception),
                'failure_message' => $this->failureMessage($exception),
                'research_completed_at' => now(),
            ]);
            if ($item->ingredient_intake_item_id !== null) {
                $intakeItem = IngredientIntakeItem::query()
                    ->lockForUpdate()
                    ->find($item->ingredient_intake_item_id);
                if ($intakeItem) {
                    $intakeItem->update([
                        'status' => IngredientIntakeItemStatus::Failed,
                        'failure_code' => $item->failure_code,
                        'failure_message' => $item->failure_message,
                    ]);
                    $this->batches->refreshIntake($intakeItem->ingredient_intake_batch_id);
                }
            }
            $this->batches->refresh($item->ingredient_enrichment_batch_id);
        }, attempts: 5);
    }

    private function failureMessage(Throwable $exception): string
    {
        if ($exception instanceof IngredientResearchProviderException) {
            return $exception->getMessage();
        }

        if ($exception instanceof ValidationException) {
            $details = collect($exception->errors())->flatten()->first();

            if (is_string($details) && $details !== '') {
                return (string) __('ingredient_enrichment_admin.validation.generated_result_invalid', [
                    'details' => $details,
                ]);
            }
        }

        return (string) __('ingredient_enrichment_admin.validation.provider_failed');
    }

    private function persistIntakeResult(int $itemId, IngredientEnrichmentPipelineResponse $response): void
    {
        DB::transaction(function () use ($itemId, $response): void {
            $item = IngredientEnrichmentBatchItem::query()->lockForUpdate()->findOrFail($itemId);
            $intakeItem = IngredientIntakeItem::query()
                ->with(['batch', 'existingIngredient'])
                ->lockForUpdate()
                ->findOrFail($item->ingredient_intake_item_id);
            if ($this->subjectBuilder->forIntake($intakeItem)->fingerprint !== $item->source_fingerprint) {
                $item->update(['status' => IngredientEnrichmentItemStatus::Stale]);
                $this->batches->refreshIntake($intakeItem->ingredient_intake_batch_id);
                $this->batches->refresh($item->ingredient_enrichment_batch_id);

                return;
            }

            $result = is_array($response->result) ? $response->result : [];
            $this->evidenceVerifier->verify($result, $response->sources);
            $report = $this->validator->validateOrFail($result);
            $normalized = $report['normalized'];
            $plan = $this->planner->planForIntake(
                $normalized,
                $intakeItem->existingIngredient,
                $intakeItem->original_current_name,
                $intakeItem->original_inci_name,
            );
            $warnings = collect($result['warnings'] ?? [])
                ->merge($result['unresolved_questions'] ?? [])
                ->merge($report['warnings'] ?? [])
                ->merge($plan['warnings'] ?? [])
                ->filter(fn (mixed $warning): bool => is_string($warning) && $warning !== '')
                ->unique()
                ->values()
                ->all();
            $item->update([
                'status' => $warnings === []
                    ? IngredientEnrichmentItemStatus::Ready
                    : IngredientEnrichmentItemStatus::Warning,
                'result' => $normalized,
                'original_result' => $item->original_result ?? $normalized,
                'validation_report' => $report,
                'plan' => $plan,
                'confidence' => $normalized['confidence'] ?? null,
                'warnings' => $warnings,
                'unresolved_questions' => is_array($normalized['unresolved_questions'] ?? null)
                    ? $normalized['unresolved_questions']
                    : [],
                'sources' => $response->sources,
                'provider_response_id' => $response->providerResponseId,
                'provider_request_id' => $response->providerRequestId,
                'provider_model' => $response->providerModel,
                'input_tokens' => $response->inputTokens,
                'output_tokens' => $response->outputTokens,
                'web_search_calls' => $response->webSearchCalls,
                'structured_source_calls' => $response->structuredSourceCalls,
                'research_completed_at' => now(),
            ]);
            $intakeItem->update([
                'status' => $warnings === []
                    ? IngredientIntakeItemStatus::Ready
                    : IngredientIntakeItemStatus::Ready,
                'failure_code' => null,
                'failure_message' => null,
            ]);
            $this->batches->refreshIntake($intakeItem->ingredient_intake_batch_id);
            $this->batches->refresh($item->ingredient_enrichment_batch_id);
        }, attempts: 5);
    }

    /** @return array<string, mixed> */
    private function failureTelemetry(IngredientEnrichmentBatchItem $item): array
    {
        $stages = is_array($item->research_stages) ? $item->research_stages : [];
        $guidanceResearch = data_get($stages, 'ai_guidance_research.data');
        $editorial = data_get($stages, 'ai_editorial.data');
        $telemetry = [
            'structured_source_calls' => collect($stages)->sum(
                fn (mixed $stage): int => is_array($stage) ? (int) ($stage['source_calls'] ?? 0) : 0,
            ),
        ];
        $providerTelemetry = collect([$editorial, $guidanceResearch])
            ->first(fn (mixed $data): bool => is_array($data)
                && filled($data['provider_response_id'] ?? null));

        foreach ([
            'provider_response_id',
            'provider_request_id',
            'provider_model',
        ] as $field) {
            if (is_array($providerTelemetry)
                && is_string($providerTelemetry[$field] ?? null)
                && $providerTelemetry[$field] !== '') {
                $telemetry[$field] = $providerTelemetry[$field];
            }
        }

        foreach (['input_tokens', 'output_tokens', 'web_search_calls'] as $field) {
            $total = collect([$guidanceResearch, $editorial])
                ->sum(fn (mixed $data): int => is_array($data) && is_numeric($data[$field] ?? null)
                    ? (int) $data[$field]
                    : 0);
            if ($total > 0) {
                $telemetry[$field] = $total;
            }
        }

        return $telemetry;
    }
}
