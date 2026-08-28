<?php

namespace App\Services\IngredientEnrichment;

use App\Data\IngredientSourceStageResult;
use App\Enums\IngredientEnrichmentBatchMode;
use App\Enums\IngredientEnrichmentResearchStage;
use App\Models\Ingredient;
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
        private readonly IngredientEnrichmentSnapshotBuilder $snapshots,
        private readonly IngredientTranslationSourceFingerprint $translationFingerprint,
        private readonly LocalizedGuidanceHeadings $headings,
    ) {}

    /**
     * @param  callable(): IngredientSourceStageResult  $callback
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
                $context = $this->context($item);
                $stored = $this->stages->stages($item)[$stage->value] ?? null;

                if (! is_array($stored) || ($stored['status'] ?? null) !== 'completed') {
                    return ['result' => null, 'context' => $context];
                }

                $storedResult = IngredientSourceStageResult::fromArray($stored);
                if ($storedResult->stage !== $stage) {
                    throw new LogicException("Unexpected enrichment stage {$storedResult->stage->value}.");
                }
                $storedResult = $this->validateResult($storedResult, $context, requireValidationEnvelope: true);

                return ['result' => $storedResult, 'context' => $context];
            }, attempts: 5);
            $stored = $execution['result'];
            $context = $execution['context'];

            if ($stored instanceof IngredientSourceStageResult) {
                return $stored;
            }

            $result = $callback();
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

            $this->stages->complete($itemId, $result);

            return $result;
        } catch (Throwable $exception) {
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
     *     soapmaking_relevant: bool
     * }  $context
     */
    private function validateResult(
        IngredientSourceStageResult $result,
        array $context,
        bool $requireValidationEnvelope = false,
    ): IngredientSourceStageResult {
        if ($result->stage === IngredientEnrichmentResearchStage::Validation) {
            return $this->validateValidation($result, $context, $requireValidationEnvelope);
        }

        match ($result->stage) {
            IngredientEnrichmentResearchStage::AiGuidanceAuthoring => $this->validateAuthoring($result->data),
            IngredientEnrichmentResearchStage::AiGuidanceLocalization => $this->validateLocalization($result->data, $context),
            default => null,
        };

        return $result;
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
                || ($storedReport['valid'] ?? null) !== true
                || ! is_array($storedReport['errors'] ?? null)
                || ! is_array($storedReport['warnings'] ?? null)
                || ($storedReport['normalized'] ?? null) !== $normalized) {
                throw new LogicException('Guidance validation stage data contains an invalid validation report.');
            }
            if (($storedReport['stale'] ?? null) !== false) {
                throw new LogicException('Guidance validation stage data contains a stale validation report.');
            }
        }

        return new IngredientSourceStageResult(
            stage: $stageResult->stage,
            status: $stageResult->status,
            data: [
                'result' => $normalized,
                'validation_report' => $report,
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
     *     soapmaking_relevant: bool
     * }
     */
    private function context(IngredientEnrichmentBatchItem $item): array
    {
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

        return [
            'mode' => $mode,
            'ingredient' => $ingredient,
            'subject_public_id' => (string) $ingredient->public_id,
            'source_fingerprint' => (string) $item->source_fingerprint,
            'expected_locales' => $mode === IngredientEnrichmentBatchMode::GuidanceLocalization
                ? $this->outdatedLocales($ingredient)
                : $this->snapshots->targetLocales(),
            'soapmaking_relevant' => $this->soapmakingRelevant($item, $ingredient),
        ];
    }

    private function soapmakingRelevant(IngredientEnrichmentBatchItem $item, Ingredient $ingredient): bool
    {
        $englishGuidance = data_get($item->research_stages, 'ai_guidance_authoring.data.guidance.info_markdown');
        if (! is_string($englishGuidance) || trim($englishGuidance) === '') {
            $englishGuidance = data_get($item->snapshot, 'current.canonical.info_markdown');
        }
        if (! is_string($englishGuidance) || trim($englishGuidance) === '') {
            $englishGuidance = (string) ($ingredient->info_markdown ?? '');
        }

        return str_contains(
            $englishGuidance,
            '## '.config('ingredient-enrichment.guidance.soapmaking_heading', 'Soapmaking'),
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

        return $ingredient->translations()
            ->get(['locale', 'source_fingerprint'])
            ->filter(fn ($translation): bool => is_string($translation->locale)
                && ($translation->source_fingerprint === null || $translation->source_fingerprint !== $fingerprint))
            ->pluck('locale')
            ->intersect($this->snapshots->targetLocales())
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
