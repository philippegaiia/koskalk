<?php

namespace App\Services\IngredientEnrichment;

use App\Contracts\IngredientGuidanceAuthoringClient;
use App\Contracts\IngredientGuidanceLocalizationClient;
use App\Contracts\IngredientGuidanceResearchClient;
use App\Data\IngredientSourceStageResult;
use App\Enums\IngredientEnrichmentBatchMode;
use App\Enums\IngredientEnrichmentItemStatus;
use App\Enums\IngredientEnrichmentResearchStage;
use App\Models\Ingredient;
use App\Models\IngredientEnrichmentBatchItem;
use Carbon\CarbonImmutable;
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
        private readonly IngredientGuidanceResearchClient $guidanceResearch,
        private readonly IngredientGuidanceEvidencePolicy $guidanceEvidencePolicy,
        private readonly IngredientGuidanceChangePlanner $planner,
        private readonly IngredientGuidanceStageRunner $stages,
        private readonly IngredientEnrichmentBatchService $batches,
        private readonly LocalizedGuidanceHeadings $headings,
    ) {}

    public function handle(int $itemId): void
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
            $snapshot = is_array($item->snapshot)
                ? $item->snapshot
                : $this->contexts->build($ingredient);
            if (! is_array($item->snapshot)) {
                $item->update(['snapshot' => $snapshot]);
            }

            return [
                'mode' => $mode->value,
                'source_fingerprint' => $item->source_fingerprint,
                'snapshot' => $snapshot,
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
            $research = $mode === IngredientEnrichmentBatchMode::GuidanceRefresh
                ? $this->runResearch($itemId, $context)
                : null;
            $guidanceContext = $research instanceof IngredientSourceStageResult
                ? $this->contextWithFreshResearch($context, $research)
                : $context;
            $authoring = $mode === IngredientEnrichmentBatchMode::GuidanceRefresh
                ? $this->runAuthoring($itemId, $guidanceContext)
                : null;
            $englishGuidance = $mode === IngredientEnrichmentBatchMode::GuidanceRefresh
                ? $this->englishGuidanceFromAuthoring($authoring)
                : $this->canonicalEnglishGuidance($context, $ingredient);
            $soapmakingRelevant = $this->headings->hasExactHeading(
                $englishGuidance,
                (string) config('ingredient-enrichment.guidance.soapmaking_heading', 'Soapmaking'),
            );
            $metadataTranslations = $this->metadataTranslations($context);
            $localization = $this->stages->run(
                $itemId,
                IngredientEnrichmentResearchStage::AiGuidanceLocalization,
                function (array $stageContext) use ($englishGuidance, $metadataTranslations): IngredientSourceStageResult {
                    $providerConfiguration = $stageContext['provider_configurations'][IngredientEnrichmentResearchStage::AiGuidanceLocalization->value] ?? [];
                    $response = $this->localization->localize([
                        'locales' => $stageContext['expected_locales'],
                        'english_guidance' => $englishGuidance,
                        'soapmaking_relevant' => $stageContext['soapmaking_relevant'],
                        'localized_headings' => is_array($providerConfiguration['localized_headings'] ?? null)
                            ? $providerConfiguration['localized_headings']
                            : [],
                        'metadata_translations' => $metadataTranslations,
                    ]);

                    return new IngredientSourceStageResult(
                        stage: IngredientEnrichmentResearchStage::AiGuidanceLocalization,
                        status: 'completed',
                        data: [
                            'translations' => $response->translations,
                            'provider_response_id' => $response->responseId,
                            'provider_request_id' => $response->requestId,
                            'provider_model' => $response->model,
                            'input_tokens' => $response->inputTokens,
                            'output_tokens' => $response->outputTokens,
                        ],
                    );
                },
            );

            $translations = $this->normalizedTranslations($localization, $soapmakingRelevant);
            $guidance = $this->authoringGuidance($authoring);
            $validation = $this->stages->run(
                $itemId,
                IngredientEnrichmentResearchStage::Validation,
                function (array $stageContext) use ($mode, $ingredient, $sourceFingerprint, $englishGuidance, $translations, $guidanceContext, $guidance, $research): IngredientSourceStageResult {
                    $providerConfiguration = $stageContext['provider_configurations'][IngredientEnrichmentResearchStage::Validation->value] ?? [];
                    $promptVersions = [
                        'guidance' => (string) ($providerConfiguration['guidance_prompt_version']
                            ?? config('ingredient-enrichment.openai.guidance_prompt_version')),
                        'localization' => (string) ($providerConfiguration['localization_prompt_version']
                            ?? config('ingredient-enrichment.openai.guidance_localization_prompt_version')),
                    ];
                    if ($research instanceof IngredientSourceStageResult) {
                        $promptVersions['research'] = (string) config('ingredient-enrichment.openai.guidance_research.prompt_version');
                    }
                    $candidate = [
                        'format' => 'soapkraft-ingredient-guidance-refresh-result',
                        'schema_version' => 1,
                        'mode' => $mode->value,
                        'subject_public_id' => (string) $ingredient->public_id,
                        'source_fingerprint' => $sourceFingerprint,
                        'info_markdown' => $englishGuidance,
                        'translations' => $translations,
                        'guidance_evidence' => is_array($guidanceContext['guidance_evidence'] ?? null)
                            ? $guidanceContext['guidance_evidence']
                            : [],
                        'prompt_versions' => $promptVersions,
                        'warnings' => collect(is_array($guidanceContext['warnings'] ?? null) ? $guidanceContext['warnings'] : [])
                            ->merge(is_array($guidance['warnings'] ?? null) ? $guidance['warnings'] : [])
                            ->filter(fn (mixed $warning): bool => is_string($warning) && trim($warning) !== '')
                            ->unique()->values()->all(),
                        'unresolved_questions' => collect(is_array($guidanceContext['unresolved_questions'] ?? null)
                            ? $guidanceContext['unresolved_questions']
                            : [])
                            ->merge(is_array($guidance['unresolved_questions'] ?? null)
                                ? $guidance['unresolved_questions']
                                : [])
                            ->filter(fn (mixed $question): bool => is_string($question) && trim($question) !== '')
                            ->unique()->values()->all(),
                    ];

                    return new IngredientSourceStageResult(
                        stage: IngredientEnrichmentResearchStage::Validation,
                        status: 'completed',
                        data: [
                            'candidate' => $candidate,
                        ],
                    );
                },
            );
            $normalized = $this->normalizedResult($validation);
            $validationReport = $this->validationReport($validation, $normalized);
            $providerData = $this->providerData($research, $authoring, $localization);

            DB::transaction(function () use (
                $itemId,
                $mode,
                $ingredient,
                $sourceFingerprint,
                $normalized,
                $validationReport,
                $providerData,
            ): void {
                $item = IngredientEnrichmentBatchItem::query()->lockForUpdate()->findOrFail($itemId);
                $currentIngredient = Ingredient::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($ingredient->id);
                if ($this->snapshots->fingerprint($currentIngredient) !== $sourceFingerprint) {
                    $item->update(['status' => IngredientEnrichmentItemStatus::Stale]);
                    $this->batches->refresh($item->ingredient_enrichment_batch_id);

                    return;
                }

                $plan = $this->planner->plan($currentIngredient, $normalized, $mode);
                $warnings = collect($validationReport['warnings'] ?? [])
                    ->merge($normalized['warnings'] ?? [])
                    ->merge($normalized['unresolved_questions'] ?? [])
                    ->filter(fn (mixed $warning): bool => is_string($warning) && trim($warning) !== '')
                    ->values()->unique()->all();
                $status = ! $plan['changed']
                    ? IngredientEnrichmentItemStatus::Unchanged
                    : ($warnings === [] ? IngredientEnrichmentItemStatus::Ready : IngredientEnrichmentItemStatus::Warning);
                $item->update([
                    'status' => $status,
                    'result' => $normalized,
                    'original_result' => $item->original_result ?? $normalized,
                    'validation_report' => $validationReport,
                    'plan' => $plan,
                    'warnings' => $warnings,
                    'unresolved_questions' => $normalized['unresolved_questions'],
                    'sources' => collect($normalized['guidance_evidence'] ?? [])
                        ->filter(fn (mixed $evidence): bool => is_array($evidence))
                        ->map(fn (array $evidence): array => [
                            'url' => (string) ($evidence['source_url'] ?? ''),
                            'title' => (string) ($evidence['source_name'] ?? ''),
                        ])
                        ->filter(fn (array $source): bool => $source['url'] !== '')
                        ->values()->all(),
                    'provider_response_id' => $providerData['response_id'],
                    'provider_request_id' => $providerData['request_id'],
                    'provider_model' => $providerData['model'],
                    'input_tokens' => $providerData['input_tokens'],
                    'output_tokens' => $providerData['output_tokens'],
                    'web_search_calls' => $providerData['web_search_calls'],
                    'structured_source_calls' => 0,
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

    /** @param array<string,mixed> $context */
    private function runResearch(int $itemId, array $context): IngredientSourceStageResult
    {
        return $this->stages->run(
            $itemId,
            IngredientEnrichmentResearchStage::AiGuidanceResearch,
            function (array $stageContext) use ($context): IngredientSourceStageResult {
                if (! (bool) config('ingredient-enrichment.openai.guidance_research.enabled', true)) {
                    return new IngredientSourceStageResult(
                        stage: IngredientEnrichmentResearchStage::AiGuidanceResearch,
                        status: 'completed',
                        data: [
                            'performed' => false,
                            'candidate_evidence' => [],
                            'guidance_evidence' => [],
                            'rejected_evidence' => [],
                            'warnings' => [(string) __('ingredient_enrichment.warnings.guidance_evidence_missing')],
                            'unresolved_questions' => [],
                            'sources' => [],
                            'provider_response_id' => '',
                            'provider_request_id' => '',
                            'provider_model' => '',
                            'input_tokens' => 0,
                            'output_tokens' => 0,
                            'web_search_calls' => 0,
                        ],
                        sourceCalls: 0,
                    );
                }

                $response = $this->guidanceResearch->research([
                    ...$context,
                    'guidance_research_prompt_version' => $stageContext['provider_configuration']['guidance_research_prompt_version'] ?? null,
                ]);
                $validation = $this->guidanceEvidencePolicy->partitionCandidates(
                    $response->candidateEvidence,
                    $response->sources,
                );
                $candidateEvidence = $validation->accepted;
                $guidanceEvidence = $this->guidanceEvidencePolicy->toPersisted(
                    $candidateEvidence,
                    CarbonImmutable::now(),
                );
                $warnings = collect($response->warnings)
                    ->when($validation->rejected !== [], fn ($warnings) => $warnings->push(
                        trans_choice(
                            'ingredient_enrichment.warnings.guidance_evidence_rejected',
                            count($validation->rejected),
                            ['count' => count($validation->rejected)],
                        ),
                    ))
                    ->when($candidateEvidence === [], fn ($warnings) => $warnings->push(
                        __('ingredient_enrichment.warnings.guidance_evidence_none_accepted'),
                    ))
                    ->filter(fn (mixed $warning): bool => is_string($warning) && trim($warning) !== '')
                    ->unique()
                    ->values()
                    ->all();

                return new IngredientSourceStageResult(
                    stage: IngredientEnrichmentResearchStage::AiGuidanceResearch,
                    status: 'completed',
                    data: [
                        'performed' => true,
                        'candidate_evidence' => $candidateEvidence,
                        'guidance_evidence' => $guidanceEvidence,
                        'rejected_evidence' => $validation->rejected,
                        'warnings' => $warnings,
                        'unresolved_questions' => $response->unresolvedQuestions,
                        'sources' => $response->sources,
                        'provider_response_id' => $response->responseId,
                        'provider_request_id' => $response->requestId,
                        'provider_model' => $response->model,
                        'input_tokens' => $response->inputTokens,
                        'output_tokens' => $response->outputTokens,
                        'web_search_calls' => $response->webSearchCalls,
                    ],
                    sourceCalls: $response->webSearchCalls,
                );
            },
        );
    }

    /** @param array<string,mixed> $context */
    private function contextWithFreshResearch(
        array $context,
        IngredientSourceStageResult $research,
    ): array {
        $researchData = $research->data;
        $evidence = is_array($researchData['guidance_evidence'] ?? null)
            ? $researchData['guidance_evidence']
            : [];

        return [
            ...$context,
            'guidance_evidence' => $evidence,
            'guidance_research' => $researchData,
            'guidance_research_prompt_version' => (string) config('ingredient-enrichment.openai.guidance_research.prompt_version'),
            'warnings' => is_array($researchData['warnings'] ?? null) ? $researchData['warnings'] : [],
            'guidance_unresolved_questions' => is_array($researchData['unresolved_questions'] ?? null)
                ? $researchData['unresolved_questions']
                : [],
            'unresolved_questions' => is_array($researchData['unresolved_questions'] ?? null)
                ? $researchData['unresolved_questions']
                : [],
        ];
    }

    /** @param array<string,mixed> $context */
    private function runAuthoring(int $itemId, array $context): IngredientSourceStageResult
    {
        return $this->stages->run(
            $itemId,
            IngredientEnrichmentResearchStage::AiGuidanceAuthoring,
            function () use ($context): IngredientSourceStageResult {
                $response = $this->authoring->author([
                    ...collect($context)->except([
                        'guidance_stage_context',
                        'prior_guidance_evidence',
                        'guidance_research',
                    ])->all(),
                    'guidance_evidence' => $context['guidance_evidence'] ?? [],
                ]);

                return new IngredientSourceStageResult(
                    stage: IngredientEnrichmentResearchStage::AiGuidanceAuthoring,
                    status: 'completed',
                    data: [
                        'guidance' => $response->guidance,
                        'provider_response_id' => $response->responseId,
                        'provider_request_id' => $response->requestId,
                        'provider_model' => $response->model,
                        'input_tokens' => $response->inputTokens,
                        'output_tokens' => $response->outputTokens,
                    ],
                );
            },
        );
    }

    private function englishGuidanceFromAuthoring(IngredientSourceStageResult $authoring): string
    {
        $englishGuidance = data_get($authoring->data, 'guidance.info_markdown');
        if (! is_string($englishGuidance) || trim($englishGuidance) === '') {
            throw new \LogicException('Completed guidance authoring data is missing English guidance.');
        }

        return $englishGuidance;
    }

    /** @param array<string,mixed> $context */
    private function canonicalEnglishGuidance(array $context, Ingredient $ingredient): string
    {
        $englishGuidance = data_get($context, 'current.canonical.info_markdown');

        return is_string($englishGuidance) && trim($englishGuidance) !== ''
            ? $englishGuidance
            : (string) ($ingredient->info_markdown ?? '');
    }

    /** @param array<string,mixed> $context @return list<array<string,mixed>> */
    private function metadataTranslations(array $context): array
    {
        return collect(data_get($context, 'current.translations', []))
            ->filter(fn (mixed $translation): bool => is_array($translation))
            ->map(fn (array $translation): array => collect($translation)->only([
                'locale', 'display_name', 'saponification_name',
            ])->all())
            ->values()
            ->all();
    }

    /** @return list<array{locale:string,info_markdown:string}> */
    private function normalizedTranslations(IngredientSourceStageResult $localization, bool $soapmakingRelevant): array
    {
        $translations = collect($localization->data['translations'] ?? [])
            ->filter(fn (mixed $translation): bool => is_array($translation))
            ->map(fn (array $translation): array => [
                'locale' => (string) ($translation['locale'] ?? ''),
                'info_markdown' => $this->headings->normalize(
                    (string) ($translation['info_markdown'] ?? ''),
                    (string) ($translation['locale'] ?? ''),
                    $soapmakingRelevant,
                ),
            ])
            ->values();
        $expectedLocales = data_get($localization->data, 'stage_context.expected_locales');
        if (is_array($expectedLocales) && array_is_list($expectedLocales)) {
            $localeOrder = collect($expectedLocales)
                ->filter(fn (mixed $locale): bool => is_string($locale))
                ->mapWithKeys(fn (string $locale, int $index): array => [$locale => $index])
                ->all();
            $translations = $translations->sortBy(
                fn (array $translation): int => $localeOrder[$translation['locale']] ?? PHP_INT_MAX,
            )->values();
        }

        return $translations->all();
    }

    /** @return array<string,mixed> */
    private function authoringGuidance(?IngredientSourceStageResult $authoring): array
    {
        $guidance = $authoring?->data['guidance'] ?? [];

        return is_array($guidance) ? $guidance : [];
    }

    /** @return array<string,mixed> */
    private function normalizedResult(IngredientSourceStageResult $validation): array
    {
        $result = $validation->data['result'] ?? null;
        if (! is_array($result)) {
            throw new \LogicException('Completed guidance validation data is missing the normalized result.');
        }

        return $result;
    }

    /** @param array<string,mixed> $normalized @return array<string,mixed> */
    private function validationReport(IngredientSourceStageResult $validation, array $normalized): array
    {
        $report = $validation->data['validation_report'] ?? null;

        return is_array($report)
            ? $report
            : [
                'valid' => true,
                'errors' => [],
                'warnings' => [],
                'normalized' => $normalized,
                'stale' => false,
            ];
    }

    /**
     * @return array{response_id:string,request_id:string,model:string,input_tokens:int,output_tokens:int,web_search_calls:int}
     */
    private function providerData(
        ?IngredientSourceStageResult $research,
        ?IngredientSourceStageResult $authoring,
        IngredientSourceStageResult $localization,
    ): array {
        $researchData = is_array($research?->data) ? $research->data : [];
        $authoringData = is_array($authoring?->data) ? $authoring->data : [];
        $localizationData = $localization->data;
        $providerResponseId = (string) ($authoringData['provider_response_id'] ?? '');
        $providerRequestId = (string) ($authoringData['provider_request_id'] ?? '');
        $providerModel = (string) ($authoringData['provider_model'] ?? '');
        if ($providerResponseId === '') {
            $providerResponseId = (string) ($localizationData['provider_response_id'] ?? '');
            $providerRequestId = (string) ($localizationData['provider_request_id'] ?? '');
            $providerModel = (string) ($localizationData['provider_model'] ?? '');
        }

        return [
            'response_id' => $providerResponseId,
            'request_id' => $providerRequestId,
            'model' => $providerModel,
            'input_tokens' => (int) ($researchData['input_tokens'] ?? 0)
                + (int) ($authoringData['input_tokens'] ?? 0)
                + (int) ($localizationData['input_tokens'] ?? 0),
            'output_tokens' => (int) ($researchData['output_tokens'] ?? 0)
                + (int) ($authoringData['output_tokens'] ?? 0)
                + (int) ($localizationData['output_tokens'] ?? 0),
            'web_search_calls' => (int) ($researchData['web_search_calls'] ?? 0),
        ];
    }
}
