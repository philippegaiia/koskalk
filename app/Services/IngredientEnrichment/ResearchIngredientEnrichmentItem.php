<?php

namespace App\Services\IngredientEnrichment;

use App\Contracts\IngredientResearchClient;
use App\Enums\IngredientEnrichmentItemStatus;
use App\Models\Ingredient;
use App\Models\IngredientEnrichmentBatchItem;
use Illuminate\Support\Facades\DB;
use Throwable;

class ResearchIngredientEnrichmentItem
{
    public function __construct(
        private readonly IngredientResearchClient $client,
        private readonly IngredientEnrichmentSnapshotBuilder $snapshotBuilder,
        private readonly IngredientEnrichmentEvidenceVerifier $evidenceVerifier,
        private readonly IngredientEnrichmentResultValidator $validator,
        private readonly IngredientEnrichmentPlanner $planner,
        private readonly IngredientEnrichmentBatchService $batches,
    ) {}

    public function handle(int $itemId): void
    {
        $record = DB::transaction(function () use ($itemId): ?array {
            $item = IngredientEnrichmentBatchItem::query()->lockForUpdate()->find($itemId);
            if (! $item || ! in_array($item->status, [IngredientEnrichmentItemStatus::Pending, IngredientEnrichmentItemStatus::Failed], true)) {
                return null;
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
            $response = $this->client->research($record);
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
                    'validation_report' => $report,
                    'plan' => $plan,
                    'confidence' => $normalized['confidence'],
                    'warnings' => $warnings,
                    'unresolved_questions' => $normalized['unresolved_questions'],
                    'sources' => $response->sources,
                    'provider_response_id' => $response->responseId,
                    'provider_request_id' => $response->requestId,
                    'provider_model' => $response->model,
                    'input_tokens' => $response->inputTokens,
                    'output_tokens' => $response->outputTokens,
                    'web_search_calls' => $response->webSearchCalls,
                    'research_completed_at' => now(),
                ]);
                $this->batches->refresh($item->ingredient_enrichment_batch_id);
            }, attempts: 5);
        } catch (Throwable $exception) {
            $this->markFailed($itemId, $exception);
            throw $exception;
        }
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
                'status' => IngredientEnrichmentItemStatus::Failed,
                'failure_code' => class_basename($exception),
                'failure_message' => __('ingredient_enrichment_admin.validation.provider_failed'),
                'research_completed_at' => now(),
            ]);
            $this->batches->refresh($item->ingredient_enrichment_batch_id);
        }, attempts: 5);
    }
}
