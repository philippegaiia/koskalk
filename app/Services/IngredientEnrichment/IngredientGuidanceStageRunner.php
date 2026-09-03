<?php

namespace App\Services\IngredientEnrichment;

use App\Data\IngredientSourceStageResult;
use App\Enums\IngredientEnrichmentBatchMode;
use App\Enums\IngredientEnrichmentResearchStage;
use App\Enums\IngredientTranslationOrigin;
use App\Models\Ingredient;
use App\Models\IngredientEnrichmentBatch;
use App\Models\IngredientEnrichmentBatchItem;
use App\Services\IngredientTranslationSourceFingerprint;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

class IngredientGuidanceStageRunner
{
    public function __construct(
        private readonly IngredientEnrichmentStageStore $stages,
        private readonly IngredientGuidanceRefreshResultValidator $validator,
        private readonly IngredientGuidanceEvidencePolicy $guidanceEvidencePolicy,
        private readonly IngredientEnrichmentSnapshotBuilder $snapshots,
        private readonly IngredientTranslationSourceFingerprint $translationFingerprint,
        private readonly LocalizedGuidanceHeadings $headings,
    ) {}

    /**
     * @param  callable(array<string,mixed>): IngredientSourceStageResult  $callback
     */
    public function run(
        int $itemId,
        IngredientEnrichmentResearchStage $stage,
        callable $callback,
    ): IngredientSourceStageResult {
        try {
            $execution = DB::transaction(function () use ($itemId, $stage): array {
                $item = IngredientEnrichmentBatchItem::query()
                    ->lockForUpdate()
                    ->findOrFail($itemId);
                $context = $this->context($item, $stage);
                if ($stage === IngredientEnrichmentResearchStage::AiGuidanceLocalization) {
                    $this->freezeExpectedLocales($item, $context['expected_locales']);
                    $context = $this->context($item, $stage);
                }
                $stored = $this->stages->stages($item)[$stage->value] ?? null;

                if (! is_array($stored) || ($stored['status'] ?? null) !== 'completed') {
                    return ['result' => null, 'context' => $context, 'invalidate' => false];
                }

                $storedResult = IngredientSourceStageResult::fromArray($stored);
                if ($storedResult->stage !== $stage) {
                    throw new LogicException("Unexpected enrichment stage {$storedResult->stage->value}.");
                }
                if ($this->stageContextProviderConfigurationChanged($storedResult, $context)) {
                    return ['result' => null, 'context' => $context, 'invalidate' => true];
                }
                $storedResult = $this->validateResult(
                    $storedResult,
                    $context,
                    requireValidationEnvelope: true,
                    requireStageContext: true,
                );

                return ['result' => $storedResult, 'context' => $context, 'invalidate' => false];
            }, attempts: 5);
            $stored = $execution['result'];
            $context = $execution['context'];

            if ($execution['invalidate']) {
                $this->stages->invalidateFrom($itemId, $stage);
            }

            if ($stored instanceof IngredientSourceStageResult) {
                return $stored;
            }

            $result = $callback($context);
            if (! $result instanceof IngredientSourceStageResult) {
                throw new LogicException("Unexpected result for enrichment stage {$stage->value}.");
            }
            if ($result->stage !== $stage) {
                throw new LogicException("Unexpected enrichment stage {$result->stage->value}.");
            }
            if ($result->status !== 'completed') {
                throw new LogicException("Enrichment stage {$stage->value} did not complete.");
            }
            $result = $this->validateResult($result, $context);
            $result = $this->withStageContext($result, $context);

            $this->stages->invalidateFrom($itemId, $stage);
            $this->stages->complete($itemId, $result);

            return $result;
        } catch (Throwable $exception) {
            $this->stages->invalidateFrom($itemId, $stage);
            $this->stages->fail($itemId, $stage, $this->safeFailureCode($exception));

            throw $exception;
        }
    }

    private function safeFailureCode(Throwable $exception): string
    {
        if ($exception instanceof IngredientResearchProviderException) {
            return $exception->failureCode;
        }

        return mb_strtolower(class_basename($exception));
    }

    /**
     * @param  array{
     *     mode: IngredientEnrichmentBatchMode,
     *     ingredient: Ingredient,
     *     subject_public_id: string,
     *     source_fingerprint: string,
     *     expected_locales: list<string>,
     *     soapmaking_relevant: bool,
     *     input_fingerprint: string,
     *     research_dependency_fingerprint: string,
     *     authoring_dependency_fingerprint: string,
     *     localization_dependency_fingerprint: string,
     *     validation_dependency_fingerprint: string,
     *     provider_configurations: array<string,array<string,mixed>>,
     *     provider_configuration_fingerprints: array<string,string>
     * }  $context
     */
    private function validateResult(
        IngredientSourceStageResult $result,
        array $context,
        bool $requireValidationEnvelope = false,
        bool $requireStageContext = false,
    ): IngredientSourceStageResult {
        if ($requireStageContext && in_array($result->stage, [
            IngredientEnrichmentResearchStage::AiGuidanceResearch,
            IngredientEnrichmentResearchStage::AiGuidanceAuthoring,
            IngredientEnrichmentResearchStage::AiGuidanceLocalization,
            IngredientEnrichmentResearchStage::Validation,
        ], true)) {
            $this->validateStageContext($result, $context);
        }

        if ($result->stage === IngredientEnrichmentResearchStage::Validation) {
            return $this->validateValidation($result, $context, $requireValidationEnvelope);
        }

        match ($result->stage) {
            IngredientEnrichmentResearchStage::AiGuidanceResearch => $this->validateResearch($result->data),
            IngredientEnrichmentResearchStage::AiGuidanceAuthoring => $this->validateAuthoring($result->data),
            IngredientEnrichmentResearchStage::AiGuidanceLocalization => $this->validateLocalization($result->data, $context),
            default => null,
        };

        return $result;
    }

    /** @param array<string,mixed> $data */
    private function validateResearch(array $data): void
    {
        $this->validateExactKeys($data, [
            'performed',
            'candidate_evidence',
            'guidance_evidence',
            'rejected_evidence',
            'warnings',
            'unresolved_questions',
            'sources',
            'provider_response_id',
            'provider_request_id',
            'provider_model',
            'input_tokens',
            'output_tokens',
            'web_search_calls',
            'stage_context',
        ], 'Guidance research stage data');

        if (! is_bool($data['performed'] ?? null)) {
            throw new LogicException('Guidance research stage data has an invalid performed flag.');
        }
        // Older completed research stages predate rejection diagnostics.
        $data['rejected_evidence'] ??= [];
        foreach (['candidate_evidence', 'guidance_evidence', 'rejected_evidence', 'sources'] as $field) {
            if (! is_array($data[$field] ?? null) || ! array_is_list($data[$field])) {
                throw new LogicException("Guidance research stage data is missing {$field}.");
            }
        }
        foreach ($data['rejected_evidence'] as $index => $rejection) {
            if (! is_array($rejection)) {
                throw new LogicException("Guidance research stage rejection {$index} is invalid.");
            }
            $this->validateExactKeys($rejection, ['index', 'code', 'host'], "Guidance research stage rejection {$index}");
            if (! is_int($rejection['index'] ?? null) || $rejection['index'] < 0
                || ! is_string($rejection['code'] ?? null)
                || ! in_array($rejection['code'], IngredientGuidanceEvidencePolicy::REJECTION_CODES, true)
                || ($rejection['host'] !== null
                    && (! is_string($rejection['host']) || trim($rejection['host']) === ''))) {
                throw new LogicException("Guidance research stage rejection {$index} is invalid.");
            }
        }
        $this->validateStringList($data, 'warnings', 'Guidance research stage data');
        $this->validateStringList($data, 'unresolved_questions', 'Guidance research stage data');
        foreach ($data['sources'] as $index => $source) {
            if (! is_array($source)) {
                throw new LogicException("Guidance research stage source {$index} is invalid.");
            }
            $this->validateExactKeys($source, ['url', 'title'], "Guidance research stage source {$index}");
            if (! is_string($source['url'] ?? null) || trim($source['url']) === ''
                || ! is_string($source['title'] ?? null) || trim($source['title']) === '') {
                throw new LogicException("Guidance research stage source {$index} is invalid.");
            }
        }
        $this->validateProviderAccounting($data, 'Guidance research stage data');
        foreach (['web_search_calls'] as $field) {
            if (! is_int($data[$field] ?? null) || $data[$field] < 0) {
                throw new LogicException("Guidance research stage data is missing {$field}.");
            }
        }

        $candidates = $this->guidanceEvidencePolicy->validateCandidates(
            $data['candidate_evidence'],
            $data['sources'],
        );
        if ($this->keySorted($candidates) !== $this->keySorted($data['candidate_evidence'])) {
            throw new LogicException('Guidance research stage data contains non-canonical evidence.');
        }
        foreach ($data['guidance_evidence'] as $index => $row) {
            if (! is_array($row)) {
                throw new LogicException("Guidance research stage evidence {$index} is invalid.");
            }
            $this->validateExactKeys($row, [
                'source_name', 'source_url', 'summary', 'source_tier', 'retrieved_at',
                'claim_type', 'source_kind', 'scope', 'evidence_kind', 'usage_application',
                'recommended_min_percent', 'recommended_max_percent', 'percentage_basis',
                'identifiers',
            ], "Guidance research stage evidence {$index}");
        }
        if (count($data['candidate_evidence']) !== count($data['guidance_evidence'])) {
            throw new LogicException('Guidance research stage evidence does not match candidate evidence.');
        }
    }

    /**
     * PostgreSQL jsonb canonicalizes object key order (length then bytes),
     * so stage data read back from the database must be compared
     * order-insensitively.
     */
    private function keySorted(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $sorted = $value;
        ksort($sorted);
        foreach ($sorted as $key => $item) {
            if (is_array($item)) {
                $sorted[$key] = $this->keySorted($item);
            }
        }

        return $sorted;
    }

    /** @param array<string,mixed> $data */
    private function validateAuthoring(array $data): void
    {
        $guidance = $data['guidance'] ?? null;
        if (! is_array($guidance)) {
            throw new LogicException('Guidance authoring stage data is missing guidance.');
        }
        $this->validateExactKeys($guidance, ['info_markdown', 'warnings', 'unresolved_questions'], 'Guidance authoring stage guidance');

        $englishGuidance = $guidance['info_markdown'] ?? null;
        if (! is_string($englishGuidance) || trim($englishGuidance) === '') {
            throw new LogicException('Guidance authoring stage data is missing English guidance.');
        }
        $this->validateStringList($guidance, 'warnings', 'Guidance authoring stage data');
        $this->validateStringList($guidance, 'unresolved_questions', 'Guidance authoring stage data');
        if (! $this->validator->validateGuidance($englishGuidance)['valid']) {
            throw new LogicException('Guidance authoring stage data contains invalid guidance.');
        }
        $this->validateProviderAccounting($data, 'Guidance authoring stage data');
    }

    /**
     * @param  array<string,mixed>  $data
     * @param  array<string,mixed>  $context
     */
    private function validateLocalization(array $data, array $context): void
    {
        $translations = $data['translations'] ?? null;
        if (! is_array($translations) || ! array_is_list($translations)) {
            throw new LogicException('Guidance localization stage data is missing translations.');
        }

        $normalizedTranslations = collect($translations)
            ->map(fn (mixed $translation): mixed => is_array($translation)
                ? $this->normalizeTranslationHeadings($translation, $context['soapmaking_relevant'])
                : $translation)
            ->all();
        if (! $this->validator->validateTranslations(
            $normalizedTranslations,
            $context['expected_locales'],
            $context['soapmaking_relevant'],
        )['valid']) {
            throw new LogicException('Guidance localization stage data contains invalid translations.');
        }
        $this->validateProviderAccounting($data, 'Guidance localization stage data');
    }

    /**
     * @param  array{
     *     mode: IngredientEnrichmentBatchMode,
     *     ingredient: Ingredient,
     *     subject_public_id: string,
     *     source_fingerprint: string,
     *     expected_locales: list<string>,
     *     soapmaking_relevant: bool
     * }  $context
     */
    private function validateValidation(
        IngredientSourceStageResult $stageResult,
        array $context,
        bool $requireValidationEnvelope,
    ): IngredientSourceStageResult {
        $data = $stageResult->data;
        if ($requireValidationEnvelope
            && (! array_key_exists('result', $data) || ! is_array($data['result']))) {
            throw new LogicException('Guidance validation stage data is missing the result envelope.');
        }
        if ($requireValidationEnvelope
            && (! array_key_exists('validation_report', $data) || ! is_array($data['validation_report']))) {
            throw new LogicException('Guidance validation stage data is missing the validation report envelope.');
        }
        $candidate = $data['candidate'] ?? $data['result'] ?? null;
        if (! is_array($candidate)) {
            throw new LogicException('Guidance validation stage data is missing the normalized result.');
        }

        if (($candidate['mode'] ?? null) !== $context['mode']->value) {
            throw new LogicException('Guidance validation stage data does not match the persisted batch mode.');
        }
        if (($candidate['subject_public_id'] ?? null) !== $context['subject_public_id']) {
            throw new LogicException('Guidance validation stage data does not match the ingredient subject.');
        }
        if (($candidate['source_fingerprint'] ?? null) !== $context['source_fingerprint']) {
            throw new LogicException('Guidance validation stage data does not match the expected source fingerprint.');
        }

        $report = $this->validator->validateOrFail(
            $candidate,
            $context['ingredient'],
            $context['mode'],
            $context['expected_locales'],
        );
        if (! is_array($report['normalized'] ?? null)) {
            throw new LogicException('Guidance validation stage data is missing canonical normalization.');
        }
        $normalized = $report['normalized'];
        $result = $data['result'] ?? null;
        if (array_key_exists('result', $data) && (! is_array($result) || $result !== $normalized)) {
            throw new LogicException('Guidance validation stage data result is not canonically normalized.');
        }
        if ($requireValidationEnvelope || array_key_exists('validation_report', $data)) {
            $storedReport = $data['validation_report'];
            if (! is_array($storedReport)
                || ! array_key_exists('result', $data)
                || $this->canonicalize($storedReport) !== $this->canonicalize($report)) {
                throw new LogicException('Guidance validation stage data contains an invalid validation report.');
            }
        }

        return new IngredientSourceStageResult(
            stage: $stageResult->stage,
            status: $stageResult->status,
            data: [
                'result' => $normalized,
                'validation_report' => $report,
                ...array_key_exists('stage_context', $stageResult->data)
                    ? ['stage_context' => $stageResult->data['stage_context']]
                    : [],
            ],
            evidence: $stageResult->evidence,
            warnings: $stageResult->warnings,
            unresolvedQuestions: $stageResult->unresolvedQuestions,
            sourceCalls: $stageResult->sourceCalls,
        );
    }

    /**
     * @return array{
     *     mode: IngredientEnrichmentBatchMode,
     *     ingredient: Ingredient,
     *     subject_public_id: string,
     *     source_fingerprint: string,
     *     expected_locales: list<string>,
     *     soapmaking_relevant: bool,
     *     input_fingerprint: string,
     *     authoring_dependency_fingerprint: string,
     *     localization_dependency_fingerprint: string,
     *     validation_dependency_fingerprint: string,
     *     provider_configurations: array<string,array<string,mixed>>,
     *     provider_configuration_fingerprints: array<string,string>
     * }
     */
    private function context(
        IngredientEnrichmentBatchItem $item,
        IngredientEnrichmentResearchStage $stage,
    ): array {
        $batch = $item->batch()->lockForUpdate()->first();
        $mode = $batch?->mode;
        $ingredient = Ingredient::query()
            ->withoutGlobalScopes()
            ->lockForUpdate()
            ->find($item->ingredient_id);
        if (! $mode instanceof IngredientEnrichmentBatchMode || ! $mode->isGuidance()
            || ! $ingredient) {
            throw new LogicException('Guidance stage context is unavailable.');
        }

        $frozenLocales = $this->frozenExpectedLocales($item);
        if ($this->hasFrozenExpectedLocales($item) && $frozenLocales === null) {
            throw new LogicException('Guidance localization stage has invalid frozen locales.');
        }
        $expectedLocales = $frozenLocales
            ?? ($mode === IngredientEnrichmentBatchMode::GuidanceLocalization
                ? $this->outdatedLocales($ingredient)
                : $this->configuredTargetLocales());
        if ($stage === IngredientEnrichmentResearchStage::AiGuidanceLocalization && $expectedLocales === []) {
            throw new LogicException('Guidance localization stage has no expected locales.');
        }

        return [
            'mode' => $mode,
            'ingredient' => $ingredient,
            'subject_public_id' => (string) $ingredient->public_id,
            'source_fingerprint' => (string) $item->source_fingerprint,
            'expected_locales' => $expectedLocales,
            'soapmaking_relevant' => $this->soapmakingRelevant($item, $ingredient, $mode),
            'input_fingerprint' => $this->inputFingerprint($item),
            'research_dependency_fingerprint' => $this->researchDependencyFingerprint($item),
            'authoring_dependency_fingerprint' => $this->authoringDependencyFingerprint($item),
            'localization_dependency_fingerprint' => $this->localizationDependencyFingerprint($item, $ingredient, $mode),
            'validation_dependency_fingerprint' => $this->validationDependencyFingerprint($item),
            'provider_configurations' => [
                IngredientEnrichmentResearchStage::AiGuidanceAuthoring->value => $this->providerConfiguration(IngredientEnrichmentResearchStage::AiGuidanceAuthoring, $batch),
                IngredientEnrichmentResearchStage::AiGuidanceResearch->value => $this->providerConfiguration(IngredientEnrichmentResearchStage::AiGuidanceResearch, $batch),
                IngredientEnrichmentResearchStage::AiGuidanceLocalization->value => $this->providerConfiguration(IngredientEnrichmentResearchStage::AiGuidanceLocalization, $batch),
                IngredientEnrichmentResearchStage::Validation->value => $this->providerConfiguration(IngredientEnrichmentResearchStage::Validation, $batch),
            ],
            'provider_configuration_fingerprints' => [
                IngredientEnrichmentResearchStage::AiGuidanceAuthoring->value => $this->providerConfigurationFingerprint(IngredientEnrichmentResearchStage::AiGuidanceAuthoring, $batch),
                IngredientEnrichmentResearchStage::AiGuidanceResearch->value => $this->providerConfigurationFingerprint(IngredientEnrichmentResearchStage::AiGuidanceResearch, $batch),
                IngredientEnrichmentResearchStage::AiGuidanceLocalization->value => $this->providerConfigurationFingerprint(IngredientEnrichmentResearchStage::AiGuidanceLocalization, $batch),
                IngredientEnrichmentResearchStage::Validation->value => $this->providerConfigurationFingerprint(IngredientEnrichmentResearchStage::Validation, $batch),
            ],
        ];
    }

    /**
     * @param  array{
     *     mode: IngredientEnrichmentBatchMode,
     *     ingredient: Ingredient,
     *     subject_public_id: string,
     *     source_fingerprint: string,
     *     expected_locales: list<string>,
     *     soapmaking_relevant: bool,
     *     input_fingerprint: string,
     *     authoring_dependency_fingerprint: string,
     *     localization_dependency_fingerprint: string,
     *     validation_dependency_fingerprint: string,
     *     provider_configurations: array<string,array<string,mixed>>,
     *     provider_configuration_fingerprints: array<string,string>
     * }  $context
     */
    private function validateStageContext(IngredientSourceStageResult $result, array $context): void
    {
        $storedContext = $result->data['stage_context'] ?? null;
        if (! is_array($storedContext)
            || $this->canonicalize($storedContext) !== $this->canonicalize($this->stageContext($result->stage, $context))) {
            throw new LogicException("Guidance {$result->stage->value} stage data has invalid provenance context.");
        }
    }

    /** @param array<string,mixed> $context */
    private function stageContextProviderConfigurationChanged(
        IngredientSourceStageResult $result,
        array $context,
    ): bool {
        $storedContext = $result->data['stage_context'] ?? null;
        if (! is_array($storedContext)) {
            return false;
        }
        $expectedContext = $this->stageContext($result->stage, $context);
        foreach (['provider_configuration', 'provider_configuration_fingerprint'] as $field) {
            unset($storedContext[$field], $expectedContext[$field]);
        }

        return $this->canonicalize($storedContext) === $this->canonicalize($expectedContext)
            && ($result->data['stage_context']['provider_configuration_fingerprint'] ?? null)
                !== ($this->stageContext($result->stage, $context)['provider_configuration_fingerprint'] ?? null);
    }

    /**
     * @param  array{
     *     mode: IngredientEnrichmentBatchMode,
     *     ingredient: Ingredient,
     *     subject_public_id: string,
     *     source_fingerprint: string,
     *     expected_locales: list<string>,
     *     soapmaking_relevant: bool,
     *     input_fingerprint: string,
     *     authoring_dependency_fingerprint: string,
     *     localization_dependency_fingerprint: string,
     *     validation_dependency_fingerprint: string,
     *     provider_configurations: array<string,array<string,mixed>>,
     *     provider_configuration_fingerprints: array<string,string>
     * }  $context
     */
    private function withStageContext(IngredientSourceStageResult $result, array $context): IngredientSourceStageResult
    {
        if (! in_array($result->stage, [
            IngredientEnrichmentResearchStage::AiGuidanceResearch,
            IngredientEnrichmentResearchStage::AiGuidanceAuthoring,
            IngredientEnrichmentResearchStage::AiGuidanceLocalization,
            IngredientEnrichmentResearchStage::Validation,
        ], true)) {
            return $result;
        }

        return new IngredientSourceStageResult(
            stage: $result->stage,
            status: $result->status,
            data: [
                ...$result->data,
                'stage_context' => $this->stageContext($result->stage, $context),
            ],
            evidence: $result->evidence,
            warnings: $result->warnings,
            unresolvedQuestions: $result->unresolvedQuestions,
            sourceCalls: $result->sourceCalls,
        );
    }

    /**
     * @param  array{
     *     mode: IngredientEnrichmentBatchMode,
     *     ingredient: Ingredient,
     *     subject_public_id: string,
     *     source_fingerprint: string,
     *     expected_locales: list<string>,
     *     soapmaking_relevant: bool,
     *     input_fingerprint: string,
     *     authoring_dependency_fingerprint: string,
     *     localization_dependency_fingerprint: string,
     *     validation_dependency_fingerprint: string,
     *     provider_configurations: array<string,array<string,mixed>>,
     *     provider_configuration_fingerprints: array<string,string>
     * }  $context
     * @return array{mode:string,subject_public_id:string,source_fingerprint:string,input_fingerprint:string,dependency_fingerprint:string,provider_configuration:array<string,mixed>,provider_configuration_fingerprint:string,expected_locales?:list<string>}
     */
    private function stageContext(IngredientEnrichmentResearchStage $stage, array $context): array
    {
        $stageContext = [
            'mode' => $context['mode']->value,
            'subject_public_id' => $context['subject_public_id'],
            'source_fingerprint' => $context['source_fingerprint'],
            'input_fingerprint' => $context['input_fingerprint'],
            'dependency_fingerprint' => match ($stage) {
                IngredientEnrichmentResearchStage::AiGuidanceResearch => $context['research_dependency_fingerprint'],
                IngredientEnrichmentResearchStage::AiGuidanceAuthoring => $context['authoring_dependency_fingerprint'],
                IngredientEnrichmentResearchStage::AiGuidanceLocalization => $context['localization_dependency_fingerprint'],
                default => $context['validation_dependency_fingerprint'],
            },
            'provider_configuration' => $this->canonicalize(
                $context['provider_configurations'][$stage->value] ?? [],
            ),
            'provider_configuration_fingerprint' => $context['provider_configuration_fingerprints'][$stage->value] ?? '',
        ];

        if (in_array($stage, [
            IngredientEnrichmentResearchStage::AiGuidanceLocalization,
            IngredientEnrichmentResearchStage::Validation,
        ], true)) {
            $stageContext['expected_locales'] = $context['expected_locales'];
        }

        return $stageContext;
    }

    /** @return list<string>|null */
    private function frozenExpectedLocales(IngredientEnrichmentBatchItem $item): ?array
    {
        $locales = data_get($item->snapshot, 'guidance_stage_context.localization.expected_locales');
        if (! $this->hasFrozenExpectedLocales($item)) {
            return null;
        }

        return $this->canonicalLocales($locales);
    }

    private function hasFrozenExpectedLocales(IngredientEnrichmentBatchItem $item): bool
    {
        $snapshot = $item->snapshot;
        $guidanceContext = is_array($snapshot) ? ($snapshot['guidance_stage_context'] ?? null) : null;
        $localizationContext = is_array($guidanceContext) ? ($guidanceContext['localization'] ?? null) : null;

        return is_array($localizationContext) && array_key_exists('expected_locales', $localizationContext);
    }

    /** @param list<string> $locales */
    private function freezeExpectedLocales(IngredientEnrichmentBatchItem $item, array $locales): void
    {
        $canonicalLocales = $this->canonicalLocales($locales);
        if ($canonicalLocales === null || $canonicalLocales === []) {
            throw new LogicException('Guidance localization stage has no valid expected locales.');
        }

        $frozen = $this->frozenExpectedLocales($item);
        if ($frozen !== null) {
            $stored = data_get($item->snapshot, 'guidance_stage_context.localization.expected_locales');
            if ($stored !== $frozen) {
                $snapshot = is_array($item->snapshot) ? $item->snapshot : [];
                data_set($snapshot, 'guidance_stage_context.localization.expected_locales', $frozen);
                $item->update(['snapshot' => $snapshot]);
                $item->setAttribute('snapshot', $snapshot);
            }

            return;
        }

        $snapshot = is_array($item->snapshot) ? $item->snapshot : [];
        data_set($snapshot, 'guidance_stage_context.localization.expected_locales', $canonicalLocales);
        $item->update(['snapshot' => $snapshot]);
        $item->setAttribute('snapshot', $snapshot);
    }

    /** @param mixed $locales @return list<string>|null */
    private function canonicalLocales(mixed $locales): ?array
    {
        if (! is_array($locales) || ! array_is_list($locales)) {
            return null;
        }

        $normalized = collect($locales)
            ->map(fn (mixed $locale): mixed => is_string($locale) ? trim($locale) : $locale)
            ->all();
        if (collect($normalized)->contains(fn (mixed $locale): bool => ! is_string($locale) || $locale === '')
            || count($normalized) !== collect($normalized)->uniqueStrict()->count()) {
            return null;
        }

        $targetLocales = $this->configuredTargetLocales();
        if (collect($normalized)->diff($targetLocales)->isNotEmpty()) {
            return null;
        }

        return collect($targetLocales)
            ->filter(fn (string $locale): bool => in_array($locale, $normalized, true))
            ->values()
            ->all();
    }

    /** @return list<string> */
    private function configuredTargetLocales(): array
    {
        return collect($this->snapshots->targetLocales())
            ->filter(fn (mixed $locale): bool => is_string($locale) && trim($locale) !== '')
            ->map(fn (string $locale): string => trim($locale))
            ->uniqueStrict()
            ->values()
            ->all();
    }

    private function inputFingerprint(IngredientEnrichmentBatchItem $item): string
    {
        $snapshot = is_array($item->snapshot) ? $item->snapshot : [];
        unset($snapshot['guidance_stage_context']);

        return hash('sha256', $this->snapshots->canonicalJson($snapshot));
    }

    private function authoringDependencyFingerprint(IngredientEnrichmentBatchItem $item): string
    {
        return hash('sha256', $this->snapshots->canonicalJson([
            'input' => $this->inputFingerprint($item),
            'research' => data_get($item->research_stages, 'ai_guidance_research.data'),
        ]));
    }

    private function researchDependencyFingerprint(IngredientEnrichmentBatchItem $item): string
    {
        return $this->inputFingerprint($item);
    }

    private function localizationDependencyFingerprint(
        IngredientEnrichmentBatchItem $item,
        Ingredient $ingredient,
        IngredientEnrichmentBatchMode $mode,
    ): string {
        $upstreamGuidance = $mode === IngredientEnrichmentBatchMode::GuidanceRefresh
            ? data_get($item->research_stages, 'ai_guidance_authoring.data.guidance')
            : null;
        if (! is_array($upstreamGuidance)) {
            $englishGuidance = data_get($item->snapshot, 'current.canonical.info_markdown');
            $upstreamGuidance = ['info_markdown' => is_string($englishGuidance) ? trim($englishGuidance) : (string) ($ingredient->info_markdown ?? '')];
        }

        return hash('sha256', $this->snapshots->canonicalJson([
            'guidance' => $upstreamGuidance,
            'expected_locales' => $this->frozenExpectedLocales($item) ?? [],
            'soapmaking_relevant' => $this->soapmakingRelevant($item, $ingredient, $mode),
        ]));
    }

    private function validationDependencyFingerprint(IngredientEnrichmentBatchItem $item): string
    {
        $stages = is_array($item->research_stages) ? $item->research_stages : [];

        return hash('sha256', $this->snapshots->canonicalJson([
            'research' => data_get($stages, 'ai_guidance_research.data'),
            'authoring' => data_get($stages, 'ai_guidance_authoring.data'),
            'localization' => data_get($stages, 'ai_guidance_localization.data'),
        ]));
    }

    private function providerConfigurationFingerprint(
        IngredientEnrichmentResearchStage $stage,
        ?IngredientEnrichmentBatch $batch,
    ): string {
        return hash('sha256', $this->snapshots->canonicalJson(
            $this->providerConfiguration($stage, $batch),
        ));
    }

    /** @return array<string,mixed> */
    private function providerConfiguration(
        IngredientEnrichmentResearchStage $stage,
        ?IngredientEnrichmentBatch $batch,
    ): array {
        $configuration = [
            'model' => (string) config('ingredient-enrichment.openai.model'),
            'reasoning_effort' => (string) config('ingredient-enrichment.openai.reasoning_effort'),
            'schema_version' => (int) config('ingredient-enrichment.schema_version'),
            'minimum_words' => (int) config('ingredient-enrichment.guidance.minimum_words', 80),
            'maximum_words' => (int) config('ingredient-enrichment.guidance.maximum_words', 160),
            'batch_model' => (string) ($batch?->model ?? ''),
            'batch_reasoning_effort' => (string) ($batch?->reasoning_effort ?? ''),
            'batch_prompt_version' => (string) ($batch?->prompt_version ?? ''),
            'batch_schema_version' => (int) ($batch?->schema_version ?? 0),
        ];
        if ($stage === IngredientEnrichmentResearchStage::AiGuidanceResearch) {
            $configuration['guidance_research_enabled'] = (bool) config('ingredient-enrichment.openai.guidance_research.enabled', true);
            $configuration['guidance_research_prompt_version'] = (string) config('ingredient-enrichment.openai.guidance_research.prompt_version');
            $configuration['guidance_research_blocked_domains'] = config('ingredient-enrichment.openai.guidance_research.blocked_domains', []);
            $configuration['guidance_research_claim_types'] = config('ingredient-enrichment.openai.guidance_research.allowed_claim_types', []);
            $configuration['guidance_research_source_kinds'] = config('ingredient-enrichment.openai.guidance_research.allowed_source_kinds', []);
            $configuration['guidance_research_scopes'] = config('ingredient-enrichment.openai.guidance_research.allowed_scopes', []);
            $configuration['guidance_research_evidence_kinds'] = config('ingredient-enrichment.openai.guidance_research.allowed_evidence_kinds', []);
            $configuration['guidance_research_applications'] = config('ingredient-enrichment.openai.guidance_research.allowed_usage_applications', []);
            $configuration['guidance_research_percentage_bases'] = config('ingredient-enrichment.openai.guidance_research.allowed_percentage_bases', []);
        }
        if (in_array($stage, [
            IngredientEnrichmentResearchStage::AiGuidanceAuthoring,
            IngredientEnrichmentResearchStage::Validation,
        ], true)) {
            $configuration['guidance_prompt_version'] = (string) config('ingredient-enrichment.openai.guidance_prompt_version');
            $configuration['required_headings'] = config('ingredient-enrichment.guidance.required_headings', []);
            $configuration['soapmaking_heading'] = (string) config('ingredient-enrichment.guidance.soapmaking_heading', 'Soapmaking');
        }
        if (in_array($stage, [
            IngredientEnrichmentResearchStage::AiGuidanceLocalization,
            IngredientEnrichmentResearchStage::Validation,
        ], true)) {
            $configuration['localization_model'] = (string) config('ingredient-enrichment.openai.localization_model');
            $configuration['localization_reasoning_effort'] = (string) config('ingredient-enrichment.openai.localization_reasoning_effort');
            $configuration['localization_prompt_version'] = (string) config('ingredient-enrichment.openai.guidance_localization_prompt_version');
            $configuration['localized_headings'] = config('ingredient-enrichment.guidance.localized_headings', []);
        }

        return $configuration;
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $canonical = collect($value)
            ->map(fn (mixed $nested): mixed => $this->canonicalize($nested))
            ->all();
        if (! array_is_list($canonical)) {
            ksort($canonical);
        }

        return $canonical;
    }

    private function soapmakingRelevant(
        IngredientEnrichmentBatchItem $item,
        Ingredient $ingredient,
        IngredientEnrichmentBatchMode $mode,
    ): bool {
        $englishGuidance = $mode === IngredientEnrichmentBatchMode::GuidanceRefresh
            ? data_get($item->research_stages, 'ai_guidance_authoring.data.guidance.info_markdown')
            : null;
        if (! is_string($englishGuidance) || trim($englishGuidance) === '') {
            $englishGuidance = data_get($item->snapshot, 'current.canonical.info_markdown');
        }
        if (! is_string($englishGuidance) || trim($englishGuidance) === '') {
            $englishGuidance = (string) ($ingredient->info_markdown ?? '');
        }

        return $this->headings->hasExactHeading(
            $englishGuidance,
            (string) config('ingredient-enrichment.guidance.soapmaking_heading', 'Soapmaking'),
        );
    }

    /** @param array<string,mixed> $translation */
    private function normalizeTranslationHeadings(array $translation, bool $soapmakingRelevant): array
    {
        $locale = $translation['locale'] ?? null;
        $guidance = $translation['info_markdown'] ?? null;
        if (! is_string($locale) || ! is_string($guidance)) {
            return $translation;
        }

        return [
            ...$translation,
            'info_markdown' => $this->headings->normalize($guidance, $locale, $soapmakingRelevant),
        ];
    }

    /** @return list<string> */
    private function outdatedLocales(Ingredient $ingredient): array
    {
        $fingerprint = $this->translationFingerprint->forIngredient($ingredient);
        $translations = $ingredient->translations()
            ->get(['locale', 'source_fingerprint', 'origin'])
            ->keyBy('locale');

        return collect($this->configuredTargetLocales())
            ->filter(function (string $locale) use ($fingerprint, $translations): bool {
                $translation = $translations->get($locale);
                if ($translation === null) {
                    return true;
                }

                if ($translation->origin === IngredientTranslationOrigin::ReviewerEdited) {
                    return false;
                }

                return $translation->source_fingerprint === null
                    || $translation->source_fingerprint !== $fingerprint;
            })
            ->values()
            ->all();
    }

    /** @param array<string,mixed> $data @param list<string> $allowed */
    private function validateExactKeys(array $data, array $allowed, string $label): void
    {
        foreach (array_diff(array_keys($data), $allowed) as $unknown) {
            throw new LogicException("{$label} contains an unexpected {$unknown} field.");
        }
    }

    /** @param array<string,mixed> $data */
    private function validateProviderAccounting(array $data, string $label): void
    {
        foreach (['provider_response_id', 'provider_request_id', 'provider_model'] as $field) {
            if (! array_key_exists($field, $data) || ! is_string($data[$field])) {
                throw new LogicException("{$label} is missing {$field}.");
            }
        }
        foreach (['input_tokens', 'output_tokens'] as $field) {
            if (! array_key_exists($field, $data)
                || ! is_int($data[$field])
                || $data[$field] < 0) {
                throw new LogicException("{$label} is missing {$field}.");
            }
        }
    }

    /** @param array<string,mixed> $data */
    private function validateStringList(array $data, string $field, string $label): void
    {
        $values = $data[$field] ?? null;
        if (! is_array($values) || ! array_is_list($values)
            || collect($values)->contains(fn (mixed $value): bool => ! is_string($value))) {
            throw new LogicException("{$label} contains an invalid {$field} list.");
        }
    }
}
