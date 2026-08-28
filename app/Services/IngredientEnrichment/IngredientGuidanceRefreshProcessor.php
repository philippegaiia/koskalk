<?php

namespace App\Services\IngredientEnrichment;

use App\Contracts\IngredientGuidanceAuthoringClient;
use App\Contracts\IngredientGuidanceLocalizationClient;
use App\Data\IngredientSourceStageResult;
use App\Enums\IngredientEnrichmentBatchMode;
use App\Enums\IngredientEnrichmentItemStatus;
use App\Enums\IngredientEnrichmentResearchStage;
use App\Models\Ingredient;
use App\Models\IngredientEnrichmentBatchItem;
use App\Services\IngredientTranslationSourceFingerprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class IngredientGuidanceRefreshProcessor
{
    public function __construct(
        private readonly IngredientGuidanceContextBuilder $contexts,
        private readonly IngredientEnrichmentSnapshotBuilder $snapshots,
        private readonly IngredientGuidanceAuthoringClient $authoring,
        private readonly IngredientGuidanceLocalizationClient $localization,
        private readonly IngredientGuidanceRefreshResultValidator $validator,
        private readonly IngredientGuidanceChangePlanner $planner,
        private readonly IngredientEnrichmentStageStore $stages,
        private readonly IngredientEnrichmentBatchService $batches,
        private readonly LocalizedGuidanceHeadings $headings,
        private readonly IngredientTranslationSourceFingerprint $translationFingerprint,
    ) {}

    public function handle(int $itemId, bool $localizationOnly = false): void
    {
        $initial = DB::transaction(function () use ($itemId): ?array {
            $item = IngredientEnrichmentBatchItem::query()->lockForUpdate()->find($itemId);
            if (! $item || ! in_array($item->status, [IngredientEnrichmentItemStatus::Pending, IngredientEnrichmentItemStatus::Failed], true)) {
                return null;
            }

            $batch = $item->batch()->lockForUpdate()->first();
            $mode = $batch?->mode;
            $ingredient = Ingredient::query()->withoutGlobalScopes()->lockForUpdate()->find($item->ingredient_id);
            if (! $batch || ! $mode instanceof IngredientEnrichmentBatchMode || ! $mode->isGuidance()
                || ! $ingredient
                || $ingredient->owner_type !== null
                || $ingredient->owner_id !== null
                || $this->snapshots->fingerprint($ingredient) !== $item->source_fingerprint) {
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

            return [
                'mode' => $mode->value,
                'source_fingerprint' => $item->source_fingerprint,
                'snapshot' => is_array($item->snapshot) ? $item->snapshot : $this->contexts->build($ingredient),
                'ingredient_id' => $ingredient->id,
            ];
        }, attempts: 5);

        if ($initial === null) {
            return;
        }

        try {
            $mode = IngredientEnrichmentBatchMode::from((string) $initial['mode']);
            $ingredient = Ingredient::query()->withoutGlobalScopes()->findOrFail((int) $initial['ingredient_id']);
            $context = is_array($initial['snapshot']) ? $initial['snapshot'] : $this->contexts->build($ingredient);
            $sourceFingerprint = (string) $initial['source_fingerprint'];
            $guidanceResponse = null;
            $englishGuidance = (string) ($ingredient->info_markdown ?? '');
            $inputTokens = 0;
            $outputTokens = 0;
            $providerResponseId = '';
            $providerRequestId = '';
            $providerModel = '';

            if (! $mode->isLocalizationOnly()) {
                $guidanceResponse = $this->authoring->author([
                    ...$context,
                    'guidance_evidence' => $context['guidance_evidence'] ?? [],
                ]);
                $englishGuidance = (string) ($guidanceResponse->guidance['info_markdown'] ?? '');
                $inputTokens += $guidanceResponse->inputTokens;
                $outputTokens += $guidanceResponse->outputTokens;
                $providerResponseId = $guidanceResponse->responseId;
                $providerRequestId = $guidanceResponse->requestId;
                $providerModel = $guidanceResponse->model;
                $this->stages->complete($itemId, new IngredientSourceStageResult(
                    stage: IngredientEnrichmentResearchStage::AiGuidanceAuthoring,
                    status: 'completed',
                    data: [
                        'guidance' => $guidanceResponse->guidance,
                        'provider_response_id' => $guidanceResponse->responseId,
                        'provider_request_id' => $guidanceResponse->requestId,
                        'provider_model' => $guidanceResponse->model,
                        'input_tokens' => $guidanceResponse->inputTokens,
                        'output_tokens' => $guidanceResponse->outputTokens,
                    ],
                ));
            }

            $soapmakingRelevant = str_contains($englishGuidance, '## '.config('ingredient-enrichment.guidance.soapmaking_heading', 'Soapmaking'));
            $locales = $mode->isLocalizationOnly()
                ? $this->outdatedLocales($ingredient)
                : $this->snapshots->targetLocales();
            $metadataTranslations = collect(data_get($context, 'current.translations', []))
                ->filter(fn (mixed $translation): bool => is_array($translation))
                ->map(fn (array $translation): array => collect($translation)->only([
                    'locale', 'display_name', 'saponification_name',
                ])->all())
                ->values()
                ->all();
            $localizationResponse = $this->localization->localize([
                'locales' => $locales,
                'english_guidance' => $englishGuidance,
                'soapmaking_relevant' => $soapmakingRelevant,
                'localized_headings' => config('ingredient-enrichment.guidance.localized_headings', []),
                'metadata_translations' => $metadataTranslations,
            ]);
            $inputTokens += $localizationResponse->inputTokens;
            $outputTokens += $localizationResponse->outputTokens;
            if ($providerResponseId === '') {
                $providerResponseId = $localizationResponse->responseId;
                $providerRequestId = $localizationResponse->requestId;
                $providerModel = $localizationResponse->model;
            }
            $this->stages->complete($itemId, new IngredientSourceStageResult(
                stage: IngredientEnrichmentResearchStage::AiGuidanceLocalization,
                status: 'completed',
                data: [
                    'translations' => $localizationResponse->translations,
                    'provider_response_id' => $localizationResponse->responseId,
                    'provider_request_id' => $localizationResponse->requestId,
                    'provider_model' => $localizationResponse->model,
                    'input_tokens' => $localizationResponse->inputTokens,
                    'output_tokens' => $localizationResponse->outputTokens,
                ],
            ));

            $translations = collect($localizationResponse->translations)
                ->filter(fn (mixed $translation): bool => is_array($translation))
                ->map(fn (array $translation): array => [
                    'locale' => (string) ($translation['locale'] ?? ''),
                    'info_markdown' => $this->headings->normalize(
                        (string) ($translation['info_markdown'] ?? ''),
                        (string) ($translation['locale'] ?? ''),
                        $soapmakingRelevant,
                    ),
                ])
                ->values()
                ->all();
            $result = [
                'format' => 'soapkraft-ingredient-guidance-refresh-result',
                'schema_version' => 1,
                'mode' => $mode->value,
                'subject_public_id' => (string) $ingredient->public_id,
                'source_fingerprint' => $sourceFingerprint,
                'info_markdown' => $englishGuidance,
                'translations' => $translations,
                'guidance_evidence' => is_array($context['guidance_evidence'] ?? null)
                    ? $context['guidance_evidence']
                    : [],
                'prompt_versions' => [
                    'guidance' => (string) config('ingredient-enrichment.openai.guidance_prompt_version'),
                    'localization' => (string) config('ingredient-enrichment.openai.guidance_localization_prompt_version'),
                ],
                'warnings' => collect($context['warnings'] ?? [])
                    ->merge(is_array($guidanceResponse?->guidance['warnings'] ?? null) ? $guidanceResponse->guidance['warnings'] : [])
                    ->filter(fn (mixed $warning): bool => is_string($warning) && trim($warning) !== '')
                    ->unique()->values()->all(),
                'unresolved_questions' => collect(is_array($guidanceResponse?->guidance['unresolved_questions'] ?? null)
                    ? $guidanceResponse->guidance['unresolved_questions']
                    : [])
                    ->filter(fn (mixed $question): bool => is_string($question) && trim($question) !== '')
                    ->unique()->values()->all(),
            ];

            DB::transaction(function () use ($itemId, $mode, $ingredient, $sourceFingerprint, $result, $locales, $inputTokens, $outputTokens, $providerResponseId, $providerRequestId, $providerModel): void {
                $item = IngredientEnrichmentBatchItem::query()->lockForUpdate()->findOrFail($itemId);
                $currentIngredient = Ingredient::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($ingredient->id);
                if ($this->snapshots->fingerprint($currentIngredient) !== $sourceFingerprint) {
                    $item->update(['status' => IngredientEnrichmentItemStatus::Stale]);
                    $this->batches->refresh($item->ingredient_enrichment_batch_id);

                    return;
                }

                $report = $this->validator->validateOrFail($result, $currentIngredient, $mode, $locales);
                $normalized = $report['normalized'];
                $plan = $this->planner->plan($currentIngredient, $normalized, $mode);
                $warnings = collect($report['warnings'])
                    ->merge($normalized['warnings'])
                    ->merge($normalized['unresolved_questions'])
                    ->values()->unique()->all();
                $status = ! $plan['changed']
                    ? IngredientEnrichmentItemStatus::Unchanged
                    : ($warnings === [] ? IngredientEnrichmentItemStatus::Ready : IngredientEnrichmentItemStatus::Warning);
                $item->update([
                    'status' => $status,
                    'result' => $normalized,
                    'original_result' => $item->original_result ?? $normalized,
                    'validation_report' => $report,
                    'plan' => $plan,
                    'warnings' => $warnings,
                    'unresolved_questions' => $normalized['unresolved_questions'],
                    'sources' => collect($normalized['guidance_evidence'])
                        ->map(fn (array $evidence): array => [
                            'url' => $evidence['source_url'],
                            'title' => $evidence['source_name'],
                        ])->all(),
                    'provider_response_id' => $providerResponseId,
                    'provider_request_id' => $providerRequestId,
                    'provider_model' => $providerModel,
                    'input_tokens' => $inputTokens,
                    'output_tokens' => $outputTokens,
                    'web_search_calls' => 0,
                    'structured_source_calls' => 0,
                    'research_completed_at' => now(),
                ]);
                $this->stages->complete($itemId, new IngredientSourceStageResult(
                    stage: IngredientEnrichmentResearchStage::Validation,
                    status: 'completed',
                    data: ['result' => $normalized],
                ));
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
                'failure_code' => $exception instanceof IngredientResearchProviderException
                    ? $exception->failureCode
                    : class_basename($exception),
                'failure_message' => $exception instanceof ValidationException
                    ? (string) (collect($exception->errors())->flatten()->first() ?? __('ingredient_enrichment_admin.validation.provider_failed'))
                    : __('ingredient_enrichment_admin.validation.provider_failed'),
                'research_completed_at' => now(),
            ]);
            $this->batches->refresh($item->ingredient_enrichment_batch_id);
        }, attempts: 5);
    }

    /** @return list<string> */
    private function outdatedLocales(Ingredient $ingredient): array
    {
        $fingerprint = $this->translationFingerprint->forIngredient($ingredient);

        return $ingredient->translations()
            ->get(['locale', 'source_fingerprint'])
            ->filter(fn ($translation): bool => is_string($translation->locale)
                && ($translation->source_fingerprint === null || $translation->source_fingerprint !== $fingerprint))
            ->pluck('locale')
            ->intersect($this->snapshots->targetLocales())
            ->values()
            ->all();
    }
}
