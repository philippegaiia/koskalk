<?php

namespace App\Services\IngredientEnrichment;

use App\Enums\IngredientEnrichmentBatchMode;
use App\Models\Ingredient;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class IngredientGuidanceRefreshResultValidator
{
    public function __construct(
        private readonly IngredientEnrichmentSnapshotBuilder $snapshots,
    ) {}

    /**
     * @return array{valid:bool,errors:array<string,list<string>>,warnings:list<string>,normalized:array<string,mixed>|null,stale:bool}
     */
    public function validate(
        array $result,
        Ingredient $ingredient,
        IngredientEnrichmentBatchMode $mode,
        ?array $expectedLocales = null,
    ): array {
        $errors = [];
        $warnings = [];
        $allowedKeys = [
            'format', 'schema_version', 'mode', 'subject_public_id', 'source_fingerprint',
            'info_markdown', 'translations', 'guidance_evidence', 'prompt_versions',
            'warnings', 'unresolved_questions',
        ];
        foreach (array_diff(array_keys($result), $allowedKeys) as $unknown) {
            $this->error($errors, 'result', "Unknown field {$unknown}.");
        }

        if (($result['format'] ?? null) !== 'soapkraft-ingredient-guidance-refresh-result') {
            $this->error($errors, 'format', 'Unsupported guidance refresh format.');
        }
        if (($result['schema_version'] ?? null) !== 1) {
            $this->error($errors, 'schema_version', 'Unsupported guidance refresh schema.');
        }
        if (($result['mode'] ?? null) !== $mode->value) {
            $this->error($errors, 'mode', 'Guidance refresh mode does not match the batch.');
        }
        if (($result['subject_public_id'] ?? null) !== (string) $ingredient->public_id) {
            $this->error($errors, 'subject_public_id', 'Guidance refresh subject does not match the ingredient.');
        }

        $sourceFingerprint = is_string($result['source_fingerprint'] ?? null)
            ? strtolower(trim($result['source_fingerprint']))
            : '';
        if (preg_match('/^[a-f0-9]{64}$/', $sourceFingerprint) !== 1) {
            $this->error($errors, 'source_fingerprint', 'Guidance refresh fingerprint is invalid.');
        }
        $currentFingerprint = $this->snapshots->fingerprint($ingredient);
        $stale = $sourceFingerprint !== '' && $sourceFingerprint !== $currentFingerprint;
        if ($stale) {
            $this->error($errors, 'source_fingerprint', 'Guidance refresh is stale.');
        }

        $english = is_string($result['info_markdown'] ?? null) ? trim($result['info_markdown']) : '';
        $soapmakingRelevant = false;
        if ($english === '') {
            $this->error($errors, 'info_markdown', 'English guidance is required.');
        } else {
            $soapmakingRelevant = $this->validateEnglishHeadings($english, $errors, $warnings);
        }

        $translations = $this->normalizeTranslations($result['translations'] ?? null, $errors, $expectedLocales, $soapmakingRelevant, $warnings);
        $guidanceEvidence = $this->normalizeEvidence($result['guidance_evidence'] ?? null, $errors);
        $promptVersions = $this->normalizePromptVersions($result['prompt_versions'] ?? null, $errors);
        $this->validateStringList($result['warnings'] ?? null, 'warnings', $errors);
        $this->validateStringList($result['unresolved_questions'] ?? null, 'unresolved_questions', $errors);

        if ($errors !== []) {
            return [
                'valid' => false,
                'errors' => $errors,
                'warnings' => $warnings,
                'normalized' => null,
                'stale' => $stale,
            ];
        }

        return [
            'valid' => true,
            'errors' => [],
            'warnings' => $warnings,
            'normalized' => [
                'format' => 'soapkraft-ingredient-guidance-refresh-result',
                'schema_version' => 1,
                'mode' => $mode->value,
                'subject_public_id' => (string) $result['subject_public_id'],
                'source_fingerprint' => $sourceFingerprint,
                'info_markdown' => $english,
                'translations' => $translations,
                'guidance_evidence' => $guidanceEvidence,
                'prompt_versions' => $promptVersions,
                'warnings' => $this->normalizeStringList($result['warnings'] ?? []),
                'unresolved_questions' => $this->normalizeStringList($result['unresolved_questions'] ?? []),
            ],
            'stale' => false,
        ];
    }

    /** @return array<string,mixed> */
    public function validateOrFail(
        array $result,
        Ingredient $ingredient,
        IngredientEnrichmentBatchMode $mode,
        ?array $expectedLocales = null,
    ): array {
        $report = $this->validate($result, $ingredient, $mode, $expectedLocales);
        if (! $report['valid']) {
            throw ValidationException::withMessages(
                collect($report['errors'])
                    ->map(fn (array $messages): string => $messages[0] ?? 'Invalid guidance refresh result.')
                    ->all(),
            );
        }

        return $report;
    }

    /** @param array<string,list<string>> $errors @param list<string> $warnings */
    private function validateEnglishHeadings(string $guidance, array &$errors, array &$warnings): bool
    {
        preg_match_all('/^##\s+(.+)$/m', $guidance, $matches);
        $headings = array_map('trim', $matches[1] ?? []);
        $required = data_get(config('ingredient-enrichment.guidance'), 'required_headings', []);
        $soapmakingHeading = (string) data_get(config('ingredient-enrichment.guidance'), 'soapmaking_heading', 'Soapmaking');
        $soapmakingRelevant = in_array($soapmakingHeading, $headings, true);
        $expected = $soapmakingRelevant ? [...$required, $soapmakingHeading] : $required;
        if ($headings !== $expected) {
            $this->error($errors, 'info_markdown', 'English guidance headings are invalid.');
        }
        $this->warnOnWordCount($guidance, 'info_markdown', $warnings);

        return $soapmakingRelevant;
    }

    /** @param array<string,list<string>> $errors @param list<string> $warnings @return list<array{locale:string,info_markdown:string}> */
    private function normalizeTranslations(
        mixed $rows,
        array &$errors,
        ?array $expectedLocales,
        bool $soapmakingRelevant,
        array &$warnings,
    ): array {
        if (! is_array($rows)) {
            $this->error($errors, 'translations', 'Translations must be an array.');

            return [];
        }

        $expectedLocales ??= array_values(config('interface-translations.catalogue_locales', []));
        $seen = [];
        $normalized = [];
        foreach ($rows as $index => $row) {
            $path = "translations.{$index}";
            if (! is_array($row)) {
                $this->error($errors, $path, 'Translation must be an object.');

                continue;
            }
            $this->validateExactKeys($row, ['locale', 'info_markdown'], $path, $errors);
            $locale = is_string($row['locale'] ?? null) ? trim($row['locale']) : '';
            if (! in_array($locale, $expectedLocales, true)) {
                $this->error($errors, "{$path}.locale", 'Translation locale is not supported.');
            }
            if (isset($seen[$locale])) {
                $this->error($errors, "{$path}.locale", 'Translation locale is duplicated.');
            }
            $seen[$locale] = true;
            $guidance = is_string($row['info_markdown'] ?? null) ? trim($row['info_markdown']) : '';
            if ($guidance === '') {
                $this->error($errors, "{$path}.info_markdown", 'Translated guidance is required.');
            } else {
                $this->validateTranslatedHeadings($guidance, $locale, $soapmakingRelevant, "{$path}.info_markdown", $errors);
                $this->warnOnWordCount($guidance, "{$path}.info_markdown", $warnings);
            }
            $normalized[] = ['locale' => $locale, 'info_markdown' => $guidance];
        }

        foreach (array_diff($expectedLocales, array_keys($seen)) as $locale) {
            $this->error($errors, 'translations', "Translation is missing for {$locale}.");
        }

        return $normalized;
    }

    /** @param array<string,list<string>> $errors */
    private function validateTranslatedHeadings(
        string $guidance,
        string $locale,
        bool $soapmakingRelevant,
        string $path,
        array &$errors,
    ): void {
        preg_match_all('/^##\s+(.+)$/m', $guidance, $matches);
        $localized = data_get(config('ingredient-enrichment.guidance'), "localized_headings.{$locale}", []);
        $expected = collect([
            $localized['overview'] ?? null,
            $localized['formulation_use'] ?? null,
            $soapmakingRelevant ? ($localized['soapmaking'] ?? null) : null,
        ])->filter(fn (mixed $heading): bool => is_string($heading) && $heading !== '')->values()->all();
        if (array_map('trim', $matches[1] ?? []) !== $expected) {
            $this->error($errors, $path, 'Translated guidance headings are invalid.');
        }
    }

    /** @param array<string,list<string>> $errors @return list<array<string,string>> */
    private function normalizeEvidence(mixed $rows, array &$errors): array
    {
        if (! is_array($rows)) {
            $this->error($errors, 'guidance_evidence', 'Guidance evidence must be an array.');

            return [];
        }
        $normalized = [];
        foreach ($rows as $index => $row) {
            $path = "guidance_evidence.{$index}";
            if (! is_array($row)) {
                $this->error($errors, $path, 'Guidance evidence must be an object.');

                continue;
            }
            $this->validateExactKeys($row, ['source_name', 'source_url', 'summary', 'source_tier', 'retrieved_at'], $path, $errors);
            foreach (['source_name', 'source_url', 'summary', 'retrieved_at'] as $field) {
                if (! is_string($row[$field] ?? null) || trim($row[$field]) === '') {
                    $this->error($errors, "{$path}.{$field}", 'Guidance evidence field is required.');
                }
            }
            if (($row['source_tier'] ?? null) !== 'editorial') {
                $this->error($errors, "{$path}.source_tier", 'Guidance evidence must be editorial.');
            }
            if (! $this->isHttpUrl($row['source_url'] ?? null)) {
                $this->error($errors, "{$path}.source_url", 'Guidance evidence URL is invalid.');
            }
            if (! $this->isIsoDateTime($row['retrieved_at'] ?? null)) {
                $this->error($errors, "{$path}.retrieved_at", 'Guidance evidence date is invalid.');
            }
            $normalized[] = [
                'source_name' => trim((string) ($row['source_name'] ?? '')),
                'source_url' => trim((string) ($row['source_url'] ?? '')),
                'summary' => trim((string) ($row['summary'] ?? '')),
                'source_tier' => 'editorial',
                'retrieved_at' => trim((string) ($row['retrieved_at'] ?? '')),
            ];
        }

        return $normalized;
    }

    /** @param array<string,list<string>> $errors @return array{guidance:string,localization:string} */
    private function normalizePromptVersions(mixed $versions, array &$errors): array
    {
        if (! is_array($versions)) {
            $this->error($errors, 'prompt_versions', 'Prompt versions are required.');

            return ['guidance' => '', 'localization' => ''];
        }
        foreach (['guidance', 'localization'] as $field) {
            if (! is_string($versions[$field] ?? null) || trim($versions[$field]) === '') {
                $this->error($errors, "prompt_versions.{$field}", 'Prompt version is required.');
            }
        }

        return [
            'guidance' => trim((string) ($versions['guidance'] ?? '')),
            'localization' => trim((string) ($versions['localization'] ?? '')),
        ];
    }

    /** @param array<string,list<string>> $errors */
    private function validateExactKeys(array $row, array $allowed, string $path, array &$errors): void
    {
        foreach (array_diff(array_keys($row), $allowed) as $unknown) {
            $this->error($errors, $path, "Unknown field {$unknown}.");
        }
    }

    /** @param array<string,list<string>> $errors */
    private function validateStringList(mixed $value, string $path, array &$errors): void
    {
        if (! is_array($value)) {
            $this->error($errors, $path, 'Value must be an array.');

            return;
        }
        foreach ($value as $index => $entry) {
            if (! is_string($entry)) {
                $this->error($errors, "{$path}.{$index}", 'Value must be a string.');
            }
        }
    }

    /** @return list<string> */
    private function normalizeStringList(mixed $value): array
    {
        return collect(is_array($value) ? $value : [])
            ->filter(fn (mixed $entry): bool => is_string($entry))
            ->map(fn (string $entry): string => trim($entry))
            ->filter()
            ->values()
            ->all();
    }

    /** @param list<string> $warnings */
    private function warnOnWordCount(string $value, string $path, array &$warnings): void
    {
        $count = preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}’\'\-]*/u', $value);
        $count = is_int($count) ? $count : 0;
        $minimum = (int) data_get(config('ingredient-enrichment.guidance'), 'minimum_words', 80);
        $maximum = (int) data_get(config('ingredient-enrichment.guidance'), 'maximum_words', 160);
        if ($count < $minimum || $count > $maximum) {
            $warnings[] = (string) __('ingredient_enrichment.warnings.word_count', [
                'path' => $path,
                'count' => $count,
                'minimum' => $minimum,
                'maximum' => $maximum,
            ]);
        }
    }

    private function isHttpUrl(mixed $value): bool
    {
        return is_string($value)
            && filter_var($value, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true);
    }

    private function isIsoDateTime(mixed $value): bool
    {
        if (! is_string($value) || trim($value) === '') {
            return false;
        }
        try {
            CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return false;
        }

        return str_contains($value, 'T');
    }

    /** @param array<string,list<string>> $errors */
    private function error(array &$errors, string $path, string $message): void
    {
        $errors[$path] ??= [];
        $errors[$path][] = $message;
    }
}
