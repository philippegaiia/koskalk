<?php

namespace App\Services\IngredientEnrichment;

use App\Data\IngredientSourceStageResult;
use App\Enums\IngredientEnrichmentBatchMode;
use App\Enums\IngredientEnrichmentResearchStage;
use App\Models\IngredientEnrichmentBatchItem;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

class IngredientGuidanceStageRunner
{
    public function __construct(
        private readonly IngredientEnrichmentStageStore $stages,
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
            $stored = DB::transaction(function () use ($itemId, $stage): ?IngredientSourceStageResult {
                $item = IngredientEnrichmentBatchItem::query()
                    ->lockForUpdate()
                    ->findOrFail($itemId);
                $stored = $this->stages->stages($item)[$stage->value] ?? null;

                if (! is_array($stored) || ($stored['status'] ?? null) !== 'completed') {
                    return null;
                }

                $storedResult = IngredientSourceStageResult::fromArray($stored);
                if ($storedResult->stage !== $stage) {
                    throw new LogicException("Unexpected enrichment stage {$storedResult->stage->value}.");
                }
                $this->validateResult($storedResult);

                return $storedResult;
            }, attempts: 5);

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
            $this->validateResult($result);

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

    private function validateResult(IngredientSourceStageResult $result): void
    {
        match ($result->stage) {
            IngredientEnrichmentResearchStage::AiGuidanceAuthoring => $this->validateAuthoring($result->data),
            IngredientEnrichmentResearchStage::AiGuidanceLocalization => $this->validateLocalization($result->data),
            IngredientEnrichmentResearchStage::Validation => $this->validateValidation($result->data),
            default => null,
        };
    }

    /** @param array<string,mixed> $data */
    private function validateAuthoring(array $data): void
    {
        $guidance = $data['guidance'] ?? null;
        if (! is_array($guidance)) {
            throw new LogicException('Guidance authoring stage data is missing guidance.');
        }

        $englishGuidance = $guidance['info_markdown'] ?? null;
        if (! is_string($englishGuidance) || trim($englishGuidance) === '') {
            throw new LogicException('Guidance authoring stage data is missing English guidance.');
        }
        $this->validateStringList($guidance, 'warnings', 'Guidance authoring stage data');
        $this->validateStringList($guidance, 'unresolved_questions', 'Guidance authoring stage data');
        $this->validateProviderAccounting($data, 'Guidance authoring stage data');
    }

    /** @param array<string,mixed> $data */
    private function validateLocalization(array $data): void
    {
        $translations = $data['translations'] ?? null;
        if (! is_array($translations) || ! array_is_list($translations)) {
            throw new LogicException('Guidance localization stage data is missing translations.');
        }

        $this->validateTranslations($translations, 'Guidance localization stage data');
        $this->validateProviderAccounting($data, 'Guidance localization stage data');
    }

    /** @param array<string,mixed> $data */
    private function validateValidation(array $data): void
    {
        $result = $data['result'] ?? null;
        if (! is_array($result)) {
            throw new LogicException('Guidance validation stage data is missing the normalized result.');
        }

        foreach (['format', 'mode', 'subject_public_id', 'source_fingerprint', 'info_markdown'] as $field) {
            if (! is_string($result[$field] ?? null) || trim($result[$field]) === '') {
                throw new LogicException("Guidance validation stage data is missing {$field}.");
            }
        }
        if (($result['format'] ?? null) !== 'soapkraft-ingredient-guidance-refresh-result') {
            throw new LogicException('Guidance validation stage data has an invalid format.');
        }
        if (($result['schema_version'] ?? null) !== 1) {
            throw new LogicException('Guidance validation stage data has an invalid schema version.');
        }
        if (! IngredientEnrichmentBatchMode::tryFrom($result['mode'])?->isGuidance()) {
            throw new LogicException('Guidance validation stage data has an invalid mode.');
        }
        if (preg_match('/^[a-f0-9]{64}$/', $result['source_fingerprint']) !== 1) {
            throw new LogicException('Guidance validation stage data has an invalid source fingerprint.');
        }
        $translations = $result['translations'] ?? null;
        if (! is_array($translations) || ! array_is_list($translations)) {
            throw new LogicException('Guidance validation stage data is missing translations.');
        }
        $this->validateTranslations($translations, 'Guidance validation stage data');
        $evidence = $result['guidance_evidence'] ?? null;
        if (! is_array($evidence) || ! array_is_list($evidence)) {
            throw new LogicException('Guidance validation stage data is missing guidance evidence.');
        }
        $this->validateEvidence($evidence);
        $this->validateStringList($result, 'warnings', 'Guidance validation stage data');
        $this->validateStringList($result, 'unresolved_questions', 'Guidance validation stage data');
        $promptVersions = $result['prompt_versions'] ?? null;
        if (! is_array($promptVersions)
            || ! is_string($promptVersions['guidance'] ?? null)
            || trim($promptVersions['guidance']) === ''
            || ! is_string($promptVersions['localization'] ?? null)
            || trim($promptVersions['localization']) === '') {
            throw new LogicException('Guidance validation stage data is missing prompt versions.');
        }

        if (array_key_exists('validation_report', $data)) {
            $report = $data['validation_report'];
            if (! is_array($report)
                || ($report['valid'] ?? null) !== true
                || ! is_array($report['errors'] ?? null)
                || ! is_array($report['warnings'] ?? null)
                || ! is_array($report['normalized'] ?? null)
                || ($report['stale'] ?? null) !== false) {
                throw new LogicException('Guidance validation stage data contains an invalid validation report.');
            }
        }
    }

    /** @param list<mixed> $translations */
    private function validateTranslations(array $translations, string $label): void
    {
        $locales = [];
        foreach ($translations as $translation) {
            if (! is_array($translation)) {
                throw new LogicException("{$label} contains an invalid translation.");
            }

            $locale = $translation['locale'] ?? null;
            if (! is_string($locale) || trim($locale) === '') {
                throw new LogicException("{$label} contains an invalid translation locale.");
            }
            if (in_array($locale, $locales, true)) {
                throw new LogicException("{$label} contains duplicate translations.");
            }
            $locales[] = $locale;

            $infoMarkdown = $translation['info_markdown'] ?? null;
            if (! is_string($infoMarkdown) || trim($infoMarkdown) === '') {
                throw new LogicException("{$label} contains an invalid translation.");
            }
        }
    }

    /** @param list<mixed> $evidence */
    private function validateEvidence(array $evidence): void
    {
        foreach ($evidence as $row) {
            if (! is_array($row)) {
                throw new LogicException('Guidance validation stage data contains invalid evidence.');
            }
            foreach (['source_name', 'source_url', 'summary', 'source_tier', 'retrieved_at'] as $field) {
                if (! is_string($row[$field] ?? null) || trim($row[$field]) === '') {
                    throw new LogicException("Guidance validation stage data evidence is missing {$field}.");
                }
            }
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
