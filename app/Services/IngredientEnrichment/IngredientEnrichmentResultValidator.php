<?php

namespace App\Services\IngredientEnrichment;

use App\Enums\IngredientAliasKind;
use App\Enums\IngredientCategory;
use App\Enums\IngredientEvidenceConfidence;
use App\Enums\IngredientIdentifierScheme;
use App\Enums\IngredientLabelMarket;
use App\Enums\IngredientSourceTier;
use App\Enums\IngredientSubcategory;
use App\Enums\IngredientValueProvenance;
use App\Models\Ingredient;
use App\Models\IngredientFunction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class IngredientEnrichmentResultValidator
{
    public function __construct(
        private readonly IngredientEnrichmentSnapshotBuilder $snapshotBuilder,
    ) {}

    /**
     * Validate a decoded result without writing anything.
     *
     * @return array{valid: bool, errors: array<string, list<string>>, warnings: list<string>, normalized: array<string, mixed>|null, stale: bool, idempotent_replay: bool}
     */
    public function validate(array $result, ?Ingredient $ingredient = null): array
    {
        $errors = [];
        $warnings = [];
        $normalized = null;
        $normalizedProposal = null;
        $stale = false;
        $idempotentReplay = false;
        $currentSchemaVersion = (int) config('ingredient-enrichment.schema_version');
        $schemaVersion = is_int($result['schema_version'] ?? null) ? $result['schema_version'] : null;
        $isCurrentSchema = $schemaVersion === $currentSchemaVersion;

        $this->validateExactKeys(
            $result,
            [
                'format', 'schema_version', 'subject_type', 'subject_public_id', 'catalog_key', 'source_fingerprint',
                'proposal', 'field_confidence', 'value_provenance', 'evidence', 'regulatory_findings', 'confidence',
                'warnings', 'unresolved_questions',
            ],
            'result',
            $errors,
        );

        if (($result['format'] ?? null) !== config('ingredient-enrichment.result_format')) {
            $this->error($errors, 'format', $this->message('unsupported_format'));
        }

        if ($schemaVersion !== $currentSchemaVersion && ! in_array($schemaVersion, [$currentSchemaVersion - 1], true)) {
            $this->error($errors, 'schema_version', $this->message('unsupported_schema'));
        }

        $subjectType = is_string($result['subject_type'] ?? null) ? trim($result['subject_type']) : 'ingredient';
        $subjectPublicId = is_string($result['subject_public_id'] ?? null) ? trim($result['subject_public_id']) : '';
        if ($isCurrentSchema && ! in_array($subjectType, ['ingredient', 'intake'], true)) {
            $this->error($errors, 'subject_type', $this->message('subject_type'));
        }
        if ($isCurrentSchema && $subjectPublicId === '') {
            $this->error($errors, 'subject_public_id', $this->message('subject_public_id'));
        }

        $catalogKey = is_string($result['catalog_key'] ?? null) ? trim($result['catalog_key']) : null;
        if ($subjectType === 'intake') {
            if ($catalogKey !== null && $catalogKey !== '') {
                $this->error($errors, 'catalog_key', $this->message('intake_catalog_key_forbidden'));
            }
            $catalogKey = null;
        } elseif ($catalogKey === null || $catalogKey === '') {
            $this->error($errors, 'catalog_key', $this->message('catalog_key_required'));
        } elseif ($ingredient instanceof Ingredient && $catalogKey !== $ingredient->catalog_key) {
            $this->error($errors, 'catalog_key', $this->message('catalog_key_mismatch'));
        }

        $sourceFingerprint = is_string($result['source_fingerprint'] ?? null)
            ? strtolower(trim($result['source_fingerprint']))
            : '';
        if (preg_match('/^[a-f0-9]{64}$/', $sourceFingerprint) !== 1) {
            $this->error($errors, 'source_fingerprint', $this->message('fingerprint_format'));
        }

        if (! is_array($result['evidence'] ?? null)) {
            $this->error($errors, 'evidence', $this->message('evidence_array'));
        }

        if (! in_array($result['confidence'] ?? null, ['low', 'medium', 'high'], true)) {
            $this->error($errors, 'confidence', $this->message('confidence'));
        }

        $this->validateStringList($result['warnings'] ?? null, 'warnings', $errors);
        $this->validateStringList($result['unresolved_questions'] ?? null, 'unresolved_questions', $errors);

        $fieldConfidence = $this->normalizeRows($result['field_confidence'] ?? null, ['field', 'confidence']);
        $inciNameIsUnresolved = collect($fieldConfidence)->contains(
            fn (array $row): bool => ($row['field'] ?? null) === 'proposal.inci_name'
                && ($row['confidence'] ?? null) === IngredientEvidenceConfidence::Unresolved->value,
        );

        $proposal = $result['proposal'] ?? null;
        if (! is_array($proposal)) {
            $this->error($errors, 'proposal', $this->message('proposal_object'));
        } else {
            $normalizedProposal = $this->validateProposal(
                $proposal,
                $inciNameIsUnresolved,
                $subjectType === 'intake',
                $errors,
                $warnings,
            );
        }

        $evidence = $this->normalizeRows($result['evidence'] ?? null, [
            'field',
            ...$this->sourceStringFields(),
        ]);
        $regulatoryFindings = $this->normalizeRows($result['regulatory_findings'] ?? null, [
            'market_code', 'finding', ...$this->sourceStringFields(),
        ]);
        $this->validateFieldConfidence($fieldConfidence, $errors);
        $this->validateEvidence($evidence, $errors);
        $this->validateFieldConfidenceAgainstEvidence($fieldConfidence, $evidence, $errors);
        $this->validateRegulatoryFindings($regulatoryFindings, $errors);
        $valueProvenance = $this->normalizeRows($result['value_provenance'] ?? null, [
            'field', 'kind', 'reasoning', 'source_urls',
        ]);
        $this->validateValueProvenance(
            $valueProvenance,
            $normalizedProposal,
            $fieldConfidence,
            $evidence,
            $isCurrentSchema,
            $errors,
        );

        if (is_array($normalizedProposal)) {
            $this->requireEvidenceForProposal($normalizedProposal, $fieldConfidence, $evidence, $valueProvenance, $errors);
            $normalized = [
                'format' => is_string($result['format'] ?? null) ? trim($result['format']) : $result['format'] ?? null,
                'schema_version' => $result['schema_version'] ?? null,
                'subject_type' => $subjectType,
                'subject_public_id' => $subjectPublicId,
                'catalog_key' => $catalogKey,
                'source_fingerprint' => $sourceFingerprint,
                'proposal' => $normalizedProposal,
                'field_confidence' => $fieldConfidence,
                'value_provenance' => $valueProvenance,
                'evidence' => $evidence,
                'regulatory_findings' => $regulatoryFindings,
                'confidence' => is_string($result['confidence'] ?? null) ? trim($result['confidence']) : $result['confidence'] ?? null,
                'warnings' => $this->normalizeStringList($result['warnings'] ?? null),
                'unresolved_questions' => $this->normalizeStringList($result['unresolved_questions'] ?? null),
            ];
        }

        if ($ingredient instanceof Ingredient && preg_match('/^[a-f0-9]{64}$/', $sourceFingerprint) === 1) {
            if ($ingredient->owner_type !== null || $ingredient->owner_id !== null) {
                $this->error($errors, 'catalog_key', $this->message('platform_only'));
            }

            $currentFingerprint = $this->snapshotBuilder->fingerprint($ingredient);
            $storedSourceFingerprint = data_get($ingredient->source_data, 'enrichment.core.source_fingerprint');
            $storedResultFingerprint = data_get($ingredient->source_data, 'enrichment.core.result_fingerprint');

            $idempotentReplay = $sourceFingerprint === $storedSourceFingerprint
                && $currentFingerprint === $storedResultFingerprint;
            $stale = $sourceFingerprint !== $currentFingerprint && ! $idempotentReplay;

            if ($stale) {
                $this->error($errors, 'source_fingerprint', $this->message('stale'));
            }
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
            'normalized' => $normalized,
            'stale' => $stale,
            'idempotent_replay' => $idempotentReplay,
        ];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function validateOrFail(array $result, ?Ingredient $ingredient = null): array
    {
        $report = $this->validate($result, $ingredient);

        if (! $report['valid']) {
            throw ValidationException::withMessages(
                collect($report['errors'])
                    ->map(fn (array $messages): string => $messages[0] ?? $this->message('invalid'))
                    ->all(),
            );
        }

        return $report;
    }

    /**
     * @return array<string, mixed>
     */
    public function validateResult(array $result, ?Ingredient $ingredient = null): array
    {
        return $this->validate($result, $ingredient);
    }

    /**
     * @param  array<string, mixed>  $proposal
     * @param  array<string, list<string>>  $errors
     * @param  list<string>  $warnings
     * @return array<string, mixed>|null
     */
    private function validateProposal(
        array $proposal,
        bool $inciNameIsUnresolved,
        bool $allowIncompleteTaxonomy,
        array &$errors,
        array &$warnings,
    ): ?array {
        $allowed = [
            'display_name',
            'inci_name',
            'category',
            'subcategory',
            'saponification_name',
            'soap_inci_naoh_name',
            'soap_inci_koh_name',
            'info_markdown',
            'soapmaking_relevant',
            'aliases',
            'identifiers',
            'cosing_functions',
            'translations',
            'market_labels',
        ];
        $this->validateExactKeys($proposal, $allowed, 'proposal', $errors);

        foreach (['display_name', 'info_markdown'] as $field) {
            if (! is_string($proposal[$field] ?? null) || trim($proposal[$field]) === '') {
                $this->error($errors, "proposal.{$field}", $this->message('required_non_empty'));
            }
        }

        $hasInciName = is_string($proposal['inci_name'] ?? null) && trim($proposal['inci_name']) !== '';
        if (! $hasInciName && ! $inciNameIsUnresolved) {
            $this->error($errors, 'proposal.inci_name', $this->message('required_non_empty'));
        }

        foreach (['saponification_name', 'soap_inci_naoh_name', 'soap_inci_koh_name'] as $field) {
            if (($proposal[$field] ?? null) !== null && ! is_string($proposal[$field])) {
                $this->error($errors, "proposal.{$field}", $this->message('string_or_null'));
            }
        }

        if (! is_bool($proposal['soapmaking_relevant'] ?? null)) {
            $this->error($errors, 'proposal.soapmaking_relevant', $this->message('boolean'));
        }

        if (($proposal['soapmaking_relevant'] ?? false) === true
            && (! is_string($proposal['saponification_name'] ?? null) || trim($proposal['saponification_name']) === '')) {
            $this->error($errors, 'proposal.saponification_name', $this->message('saponification_name_required'));
        }

        $category = is_string($proposal['category'] ?? null)
            ? IngredientCategory::tryFrom($proposal['category'])
            : null;
        $subcategory = ($proposal['subcategory'] ?? null) === null
            ? null
            : (is_string($proposal['subcategory']) ? IngredientSubcategory::tryFrom($proposal['subcategory']) : null);

        if (! $category instanceof IngredientCategory && ! ($allowIncompleteTaxonomy && ($proposal['category'] ?? null) === null)) {
            $this->error($errors, 'proposal.category', $this->message('unknown_category'));
        }

        if (($proposal['subcategory'] ?? null) !== null && ! $subcategory instanceof IngredientSubcategory) {
            $this->error($errors, 'proposal.subcategory', $this->message('unknown_subcategory'));
        }

        if ($category instanceof IngredientCategory
            && (($category === IngredientCategory::Other && $subcategory !== null)
                || ($category !== IngredientCategory::Other
                    && (! $subcategory instanceof IngredientSubcategory || $subcategory->category() !== $category)))) {
            $this->error($errors, 'proposal.subcategory', $this->message('subcategory_incompatible'));
        }

        $inciName = $hasInciName ? $this->normalizeInciName((string) $proposal['inci_name']) : null;
        $isCiName = is_string($inciName) && preg_match('/^CI [0-9]{5}$/', $inciName) === 1;
        if ($category === IngredientCategory::Colourants && $hasInciName && ! $isCiName) {
            $this->error($errors, 'proposal.inci_name', $this->message('colourant_ci'));
        }
        if ($category instanceof IngredientCategory && $category !== IngredientCategory::Colourants && $isCiName) {
            $this->error($errors, 'proposal.inci_name', $this->message('ci_reserved'));
        }

        $this->validateGuidance($proposal, $errors, $warnings);
        $this->validateAliases($proposal['aliases'] ?? [], $errors);
        $this->validateIdentifiers($proposal['identifiers'] ?? null, $errors);
        $this->validateCosIngFunctions($proposal['cosing_functions'] ?? null, $errors);
        $this->validateTranslations(
            $proposal['translations'] ?? null,
            (bool) ($proposal['soapmaking_relevant'] ?? false),
            $errors,
            $warnings,
        );
        $this->validateMarketLabels($proposal['market_labels'] ?? null, $category, $errors);

        $marketLabels = $this->normalizeRows($proposal['market_labels'] ?? null, [
            'market_code', 'declaration_name', 'reviewed_at', 'effective_from', 'effective_until', ...$this->sourceStringFields(),
        ]);
        $marketLabels = collect($marketLabels)
            ->map(function (array $row): array {
                if (($row['market_code'] ?? null) === IngredientLabelMarket::Eu->value
                    && is_string($row['declaration_name'] ?? null)) {
                    $row['declaration_name'] = $this->normalizeInciName($row['declaration_name']);
                }

                return $row;
            })
            ->all();

        return [
            'display_name' => trim((string) ($proposal['display_name'] ?? '')),
            'inci_name' => $inciName,
            'category' => $category?->value,
            'subcategory' => $subcategory?->value,
            'saponification_name' => $this->nullableString($proposal['saponification_name'] ?? null),
            'soap_inci_naoh_name' => is_string($proposal['soap_inci_naoh_name'] ?? null)
                ? $this->normalizeInciName($proposal['soap_inci_naoh_name'])
                : null,
            'soap_inci_koh_name' => is_string($proposal['soap_inci_koh_name'] ?? null)
                ? $this->normalizeInciName($proposal['soap_inci_koh_name'])
                : null,
            'info_markdown' => trim((string) ($proposal['info_markdown'] ?? '')),
            'soapmaking_relevant' => (bool) ($proposal['soapmaking_relevant'] ?? false),
            'aliases' => $this->normalizeRows($proposal['aliases'] ?? null, [
                'locale', 'name', 'kind', ...$this->sourceStringFields(),
            ]),
            'identifiers' => $this->normalizeRows($proposal['identifiers'] ?? null, [
                'scheme', 'value', ...$this->sourceStringFields(),
            ]),
            'cosing_functions' => $this->normalizeRows($proposal['cosing_functions'] ?? null, [
                'key', ...$this->sourceStringFields(),
            ]),
            'translations' => $this->normalizeRows($proposal['translations'] ?? null, [
                'locale', 'display_name', 'saponification_name', 'info_markdown',
            ]),
            'market_labels' => $marketLabels,
        ];
    }

    private function normalizeInciName(string $value): string
    {
        $name = Str::squish($value);

        if (preg_match('/^CI\s*(?<number>[0-9]{5})$/i', $name, $matches) === 1) {
            return 'CI '.$matches['number'];
        }

        return Str::ucfirst(Str::lower($name));
    }

    /**
     * @param  list<string>  $stringFields
     * @return array<int, array<string, mixed>>
     */
    private function normalizeRows(mixed $rows, array $stringFields): array
    {
        if (! is_array($rows)) {
            return [];
        }

        return collect($rows)
            ->filter(fn (mixed $row): bool => is_array($row))
            ->map(function (array $row) use ($stringFields): array {
                foreach ($stringFields as $field) {
                    if (is_string($row[$field] ?? null)) {
                        $row[$field] = trim($row[$field]);
                    }
                }

                return $row;
            })
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function normalizeStringList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return collect($values)
            ->filter(fn (mixed $value): bool => is_string($value))
            ->map(fn (string $value): string => trim($value))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $proposal
     * @param  array<string, list<string>>  $errors
     * @param  list<string>  $warnings
     */
    private function validateGuidance(array $proposal, array &$errors, array &$warnings): void
    {
        $guidance = is_string($proposal['info_markdown'] ?? null) ? trim($proposal['info_markdown']) : '';
        preg_match_all('/^##\s+(.+)$/m', $guidance, $matches);
        $headings = array_map('trim', $matches[1] ?? []);
        $required = data_get(config('ingredient-enrichment.guidance'), 'required_headings', []);
        $soapmakingHeading = (string) data_get(config('ingredient-enrichment.guidance'), 'soapmaking_heading', 'Soapmaking');

        $hasSoapmakingHeading = in_array($soapmakingHeading, $headings, true);
        if (($proposal['soapmaking_relevant'] ?? false) === true && ! $hasSoapmakingHeading) {
            $this->error($errors, 'proposal.info_markdown', $this->message('soapmaking_guidance_required'));
        }
        if (($proposal['soapmaking_relevant'] ?? false) === false && $hasSoapmakingHeading) {
            $this->error($errors, 'proposal.soapmaking_relevant', $this->message('soapmaking_flag_required'));
        }

        $expectedHeadings = ($proposal['soapmaking_relevant'] ?? false) === true
            ? [...$required, $soapmakingHeading]
            : $required;
        if ($headings !== $expectedHeadings) {
            $this->error($errors, 'proposal.info_markdown', $this->message('guidance_headings'));
        }

        $this->warnOnWordCount($guidance, 'proposal.info_markdown', $warnings);
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    private function validateIdentifiers(mixed $rows, array &$errors): void
    {
        if (! is_array($rows)) {
            $this->error($errors, 'proposal.identifiers', $this->message('identifiers_array'));

            return;
        }

        $seen = [];
        $primary = [];
        foreach ($rows as $index => $row) {
            $path = "proposal.identifiers.{$index}";
            if (! is_array($row)) {
                $this->error($errors, $path, $this->message('identifier_object'));

                continue;
            }
            $this->validateExactKeys($row, ['scheme', 'value', 'is_primary', ...$this->sourceFieldKeys()], $path, $errors);
            $scheme = is_string($row['scheme'] ?? null) ? IngredientIdentifierScheme::tryFrom($row['scheme']) : null;
            if (! $scheme instanceof IngredientIdentifierScheme) {
                $this->error($errors, "{$path}.scheme", $this->message('identifier_scheme'));
            }
            $value = is_string($row['value'] ?? null) ? trim($row['value']) : '';
            if ($value === '') {
                $this->error($errors, "{$path}.value", $this->message('identifier_value'));
            }
            if (! is_bool($row['is_primary'] ?? null)) {
                $this->error($errors, "{$path}.is_primary", $this->message('identifier_primary_boolean'));
            }
            $this->validateSourceFields($row, $path, $errors);
            $key = ($scheme?->value ?? '').'|'.strtoupper($value);
            if (isset($seen[$key])) {
                $this->error($errors, "{$path}.value", $this->message('identifier_duplicate'));
            }
            $seen[$key] = true;
            if (($row['is_primary'] ?? false) === true && isset($primary[$scheme?->value])) {
                $this->error($errors, "{$path}.is_primary", $this->message('identifier_primary_unique'));
            }
            if (($row['is_primary'] ?? false) === true && $scheme instanceof IngredientIdentifierScheme) {
                $primary[$scheme->value] = true;
            }
        }
    }

    /** @param array<string, list<string>> $errors */
    private function validateAliases(mixed $rows, array &$errors): void
    {
        if (! is_array($rows)) {
            $this->error($errors, 'proposal.aliases', $this->message('aliases_array'));

            return;
        }

        $seen = [];
        foreach ($rows as $index => $row) {
            $path = "proposal.aliases.{$index}";
            if (! is_array($row)) {
                $this->error($errors, $path, $this->message('alias_object'));

                continue;
            }

            $this->validateExactKeys($row, ['locale', 'name', 'kind', ...$this->sourceFieldKeys()], $path, $errors);
            $locale = is_string($row['locale'] ?? null) ? trim($row['locale']) : '';
            $name = is_string($row['name'] ?? null) ? Str::squish($row['name']) : '';
            if (preg_match('/^(?:und|[a-z]{2,3}(?:[-_][A-Z][a-z]{3})?)$/', $locale) !== 1) {
                $this->error($errors, "{$path}.locale", $this->message('alias_locale'));
            }
            if ($name === '') {
                $this->error($errors, "{$path}.name", $this->message('required_non_empty'));
            }
            if (! IngredientAliasKind::tryFrom((string) ($row['kind'] ?? '')) instanceof IngredientAliasKind) {
                $this->error($errors, "{$path}.kind", $this->message('alias_kind'));
            }
            $key = Str::lower($locale.'|'.$name);
            if (isset($seen[$key])) {
                $this->error($errors, "{$path}.name", $this->message('alias_duplicate'));
            }
            $seen[$key] = true;
            $this->validateSourceFields($row, $path, $errors);
        }
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    private function validateCosIngFunctions(mixed $rows, array &$errors): void
    {
        if (! is_array($rows)) {
            $this->error($errors, 'proposal.cosing_functions', $this->message('cosing_functions_array'));

            return;
        }

        $keys = collect($rows)
            ->filter(fn (mixed $row): bool => is_array($row))
            ->pluck('key')
            ->filter(fn (mixed $key): bool => is_string($key))
            ->values();
        $activeKeys = IngredientFunction::query()
            ->where('is_active', true)
            ->whereIn('key', $keys->all())
            ->pluck('key')
            ->all();

        $seen = [];
        foreach ($rows as $index => $row) {
            $path = "proposal.cosing_functions.{$index}";
            if (! is_array($row)) {
                $this->error($errors, $path, $this->message('cosing_function_object'));

                continue;
            }
            $this->validateExactKeys($row, ['key', ...$this->sourceFieldKeys()], $path, $errors);
            $key = is_string($row['key'] ?? null) ? trim($row['key']) : '';
            if ($key === '' || ! in_array($key, $activeKeys, true)) {
                $this->error($errors, "{$path}.key", $this->message('cosing_function_unknown'));
            }
            if (isset($seen[$key])) {
                $this->error($errors, "{$path}.key", $this->message('cosing_function_duplicate'));
            }
            $seen[$key] = true;
            $this->validateSourceFields($row, $path, $errors);
        }
    }

    /**
     * @param  array<string, list<string>>  $errors
     * @param  list<string>  $warnings
     */
    private function validateTranslations(mixed $rows, bool $soapmakingRelevant, array &$errors, array &$warnings): void
    {
        if (! is_array($rows)) {
            $this->error($errors, 'proposal.translations', $this->message('translations_array'));

            return;
        }

        $expectedLocales = $this->snapshotBuilder->targetLocales();
        $seen = [];
        foreach ($rows as $index => $row) {
            $path = "proposal.translations.{$index}";
            if (! is_array($row)) {
                $this->error($errors, $path, $this->message('translation_object'));

                continue;
            }
            $this->validateExactKeys($row, ['locale', 'display_name', 'saponification_name', 'info_markdown'], $path, $errors);
            $locale = is_string($row['locale'] ?? null) ? trim($row['locale']) : '';
            if (! in_array($locale, $expectedLocales, true)) {
                $this->error($errors, "{$path}.locale", $this->message('translation_locale'));
            }
            if (isset($seen[$locale])) {
                $this->error($errors, "{$path}.locale", $this->message('translation_duplicate'));
            }
            $seen[$locale] = true;
            if (! is_string($row['display_name'] ?? null) || trim($row['display_name']) === '') {
                $this->error($errors, "{$path}.display_name", $this->message('translation_display_name'));
            }
            if (! is_string($row['info_markdown'] ?? null) || trim($row['info_markdown']) === '') {
                $this->error($errors, "{$path}.info_markdown", $this->message('translation_guidance'));
            }
            if (($row['saponification_name'] ?? null) !== null && ! is_string($row['saponification_name'])) {
                $this->error($errors, "{$path}.saponification_name", $this->message('string_or_null'));
            }
            if ($soapmakingRelevant
                && (! is_string($row['saponification_name'] ?? null) || trim($row['saponification_name']) === '')) {
                $this->error($errors, "{$path}.saponification_name", $this->message('saponification_name_required'));
            }
            $this->validateTranslatedGuidance(
                is_string($row['info_markdown'] ?? null) ? $row['info_markdown'] : '',
                "{$path}.info_markdown",
                $locale,
                $soapmakingRelevant,
                $errors,
                $warnings,
            );
        }

        foreach (array_diff($expectedLocales, array_keys($seen)) as $locale) {
            $this->error($errors, 'proposal.translations', $this->message('translation_missing', ['locale' => $locale]));
        }
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    private function validateMarketLabels(mixed $rows, ?IngredientCategory $category, array &$errors): void
    {
        if (! is_array($rows)) {
            $this->error($errors, 'proposal.market_labels', $this->message('market_labels_array'));

            return;
        }

        $allowedMarkets = array_values(config('ingredient-enrichment.market_codes', []));
        $seen = [];
        foreach ($rows as $index => $row) {
            $path = "proposal.market_labels.{$index}";
            if (! is_array($row)) {
                $this->error($errors, $path, $this->message('market_label_object'));

                continue;
            }
            $this->validateExactKeys($row, ['market_code', 'declaration_name', 'reviewed_at', 'effective_from', 'effective_until', ...$this->sourceFieldKeys()], $path, $errors);
            $marketCode = is_string($row['market_code'] ?? null) ? trim($row['market_code']) : '';
            if (! in_array($marketCode, $allowedMarkets, true)) {
                $this->error($errors, "{$path}.market_code", $this->message('market_code'));
            }
            if (isset($seen[$marketCode])) {
                $this->error($errors, "{$path}.market_code", $this->message('market_duplicate'));
            }
            $seen[$marketCode] = true;
            if (! is_string($row['declaration_name'] ?? null) || trim($row['declaration_name']) === '') {
                $this->error($errors, "{$path}.declaration_name", $this->message('market_declaration_name'));
            }
            if ($marketCode === IngredientLabelMarket::Us->value && preg_match('/^CI\s*[0-9]{5}$/i', trim((string) ($row['declaration_name'] ?? ''))) === 1) {
                $this->error($errors, "{$path}.declaration_name", $this->message('market_us_bare_ci'));
            }
            $this->validateSourceFields($row, $path, $errors);
            foreach (['reviewed_at', 'effective_from', 'effective_until'] as $field) {
                if (($row[$field] ?? null) !== null && ! $this->isIsoDate($row[$field])) {
                    $this->error($errors, "{$path}.{$field}", $this->message('date_format'));
                }
            }
            if (($row['effective_from'] ?? null) !== null
                && ($row['effective_until'] ?? null) !== null
                && $row['effective_until'] < $row['effective_from']) {
                $this->error($errors, "{$path}.effective_until", $this->message('date_order'));
            }
        }

    }

    /**
     * Translated guidance may localize the section headings while preserving the same section count/order.
     *
     * @param  array<string, list<string>>  $errors
     * @param  list<string>  $warnings
     */
    private function validateTranslatedGuidance(
        string $guidance,
        string $path,
        string $locale,
        bool $soapmakingRelevant,
        array &$errors,
        array &$warnings,
    ): void {
        preg_match_all('/^##\s+(.+)$/m', $guidance, $matches);
        $headings = $matches[1] ?? [];

        $localized = data_get(config('ingredient-enrichment.guidance'), "localized_headings.{$locale}", []);
        $expectedHeadings = collect([
            $localized['overview'] ?? null,
            $localized['formulation_use'] ?? null,
            $soapmakingRelevant ? ($localized['soapmaking'] ?? null) : null,
        ])->filter(fn (mixed $heading): bool => is_string($heading) && $heading !== '')->values()->all();
        if ($headings !== $expectedHeadings) {
            $this->error($errors, $path, $this->message('translated_guidance_sections'));
        }

        $this->warnOnWordCount($guidance, $path, $warnings);
    }

    /**
     * @param  array<int, array<string, mixed>>  $evidence
     * @param  array<string, list<string>>  $errors
     */
    private function validateEvidence(array $evidence, array &$errors): void
    {
        foreach ($evidence as $index => $row) {
            $path = "evidence.{$index}";
            if (! is_array($row)) {
                $this->error($errors, $path, $this->message('evidence_object'));

                continue;
            }
            $this->validateExactKeys($row, ['field', ...$this->sourceFieldKeys()], $path, $errors);
            if (! is_string($row['field'] ?? null) || trim($row['field']) === '') {
                $this->error($errors, "{$path}.field", $this->message('evidence_field'));
            }
            $this->validateSourceFields(
                $row,
                $path,
                $errors,
            );
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, list<string>>  $errors
     */
    private function validateFieldConfidence(array $rows, array &$errors): void
    {
        $seen = [];
        foreach ($rows as $index => $row) {
            $path = "field_confidence.{$index}";
            $this->validateExactKeys($row, ['field', 'confidence'], $path, $errors);
            $field = is_string($row['field'] ?? null) ? trim($row['field']) : '';
            if ($field === '') {
                $this->error($errors, "{$path}.field", $this->message('evidence_field'));
            } elseif (isset($seen[$field])) {
                $this->error($errors, "{$path}.field", $this->message('field_confidence_duplicate'));
            }
            $seen[$field] = true;

            if (! is_string($row['confidence'] ?? null)
                || ! IngredientEvidenceConfidence::tryFrom($row['confidence']) instanceof IngredientEvidenceConfidence) {
                $this->error($errors, "{$path}.confidence", $this->message('evidence_confidence'));
            }
        }
    }

    /**
     * A field may only claim confidence that is supported by evidence for that
     * same field. AI- and reviewer-authored fields may remain supported without
     * source evidence, but a verified field always needs verified evidence.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, array<string, mixed>>  $evidence
     * @param  array<string, list<string>>  $errors
     */
    private function validateFieldConfidenceAgainstEvidence(array $rows, array $evidence, array &$errors): void
    {
        $evidenceByField = collect($evidence)->groupBy('field');

        foreach ($rows as $index => $row) {
            $field = is_string($row['field'] ?? null) ? trim($row['field']) : '';
            $confidence = is_string($row['confidence'] ?? null)
                ? IngredientEvidenceConfidence::tryFrom($row['confidence'])
                : null;

            if ($field === '' || ! $confidence instanceof IngredientEvidenceConfidence
                || $confidence === IngredientEvidenceConfidence::Unresolved) {
                continue;
            }

            $fieldEvidence = $evidenceByField->get($field, collect());
            $highestEvidenceRank = $fieldEvidence
                ->map(fn (mixed $entry): ?int => is_array($entry)
                    ? $this->confidenceRank(IngredientEvidenceConfidence::tryFrom((string) ($entry['confidence'] ?? '')))
                    : null)
                ->filter(fn (?int $rank): bool => $rank !== null)
                ->max();

            if ($highestEvidenceRank === null && $confidence !== IngredientEvidenceConfidence::Verified) {
                continue;
            }

            if ($highestEvidenceRank === null || $this->confidenceRank($confidence) > $highestEvidenceRank) {
                $this->error($errors, "field_confidence.{$index}.confidence", $this->message('field_confidence_exceeds_evidence'));
            }
        }
    }

    private function confidenceRank(?IngredientEvidenceConfidence $confidence): int
    {
        return match ($confidence) {
            IngredientEvidenceConfidence::Verified => 3,
            IngredientEvidenceConfidence::Supported => 2,
            IngredientEvidenceConfidence::Conflicting => 1,
            IngredientEvidenceConfidence::Unresolved, null => 0,
        };
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, list<string>>  $errors
     */
    private function validateRegulatoryFindings(array $rows, array &$errors): void
    {
        foreach ($rows as $index => $row) {
            $path = "regulatory_findings.{$index}";
            $this->validateExactKeys($row, ['market_code', 'finding', ...$this->sourceFieldKeys()], $path, $errors);
            if (! IngredientLabelMarket::tryFrom((string) ($row['market_code'] ?? '')) instanceof IngredientLabelMarket) {
                $this->error($errors, "{$path}.market_code", $this->message('market_code'));
            }
            if (! is_string($row['finding'] ?? null) || trim($row['finding']) === '') {
                $this->error($errors, "{$path}.finding", $this->message('required_non_empty'));
            }
            $this->validateSourceFields($row, $path, $errors);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>|null  $proposal
     * @param  array<int, array<string, mixed>>  $fieldConfidence
     * @param  array<int, array<string, mixed>>  $evidence
     * @param  array<string, list<string>>  $errors
     */
    private function validateValueProvenance(
        array $rows,
        ?array $proposal,
        array $fieldConfidence,
        array $evidence,
        bool $required,
        array &$errors,
    ): void {
        if ($required && $rows === []) {
            $this->error($errors, 'value_provenance', $this->message('value_provenance_array'));

            return;
        }

        $seen = [];
        foreach ($rows as $index => $row) {
            $path = "value_provenance.{$index}";
            if (! is_array($row)) {
                $this->error($errors, $path, $this->message('value_provenance_object'));

                continue;
            }

            $this->validateExactKeys($row, ['field', 'kind', 'reasoning', 'source_urls'], $path, $errors);
            $field = is_string($row['field'] ?? null) ? trim($row['field']) : '';
            if ($field === '') {
                $this->error($errors, "{$path}.field", $this->message('value_provenance_field'));
            } elseif (isset($seen[$field])) {
                $this->error($errors, "{$path}.field", $this->message('value_provenance_duplicate'));
            }
            $seen[$field] = true;

            $kind = is_string($row['kind'] ?? null) ? IngredientValueProvenance::tryFrom($row['kind']) : null;
            if (! $kind instanceof IngredientValueProvenance) {
                $this->error($errors, "{$path}.kind", $this->message('value_provenance_kind'));
            }
            if (! is_string($row['reasoning'] ?? null) || trim($row['reasoning']) === '') {
                $this->error($errors, "{$path}.reasoning", $this->message('value_provenance_reasoning'));
            }
            if (! is_array($row['source_urls'] ?? null)) {
                $this->error($errors, "{$path}.source_urls", $this->message('value_provenance_source_urls'));
            } else {
                foreach ($row['source_urls'] as $sourceIndex => $sourceUrl) {
                    if (! $this->isHttpUrl($sourceUrl)) {
                        $this->error($errors, "{$path}.source_urls.{$sourceIndex}", $this->message('value_provenance_source_url'));
                    }
                }
            }
        }

        if (! $required || ! is_array($proposal)) {
            return;
        }

        $provenanceByField = collect($rows)->keyBy('field');
        foreach ($this->materialProposalFields($proposal) as $field) {
            if (! $provenanceByField->has($field)) {
                $this->error($errors, 'value_provenance', $this->message('value_provenance_missing', ['field' => $field]));
            }
        }

        $confidenceByField = collect($fieldConfidence)->keyBy('field');
        $evidenceByField = collect($evidence)->groupBy('field');
        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $path = "value_provenance.{$index}";
            $field = (string) ($row['field'] ?? '');
            $kind = IngredientValueProvenance::tryFrom((string) ($row['kind'] ?? ''));
            $confidence = $confidenceByField->get($field)['confidence'] ?? null;
            $fieldEvidence = $evidenceByField->get($field, collect());
            $sourceUrls = collect(is_array($row['source_urls'] ?? null) ? $row['source_urls'] : [])
                ->filter(fn (mixed $url): bool => is_string($url))
                ->values();

            if ($kind === IngredientValueProvenance::SourceConfirmed && $fieldEvidence->isEmpty()) {
                $this->error($errors, "{$path}.kind", $this->message('source_confirmed_requires_evidence'));
            }
            if ($kind === IngredientValueProvenance::SourceConfirmed && $sourceUrls->isNotEmpty()) {
                $evidenceUrls = $fieldEvidence
                    ->pluck('source_url')
                    ->filter(fn (mixed $url): bool => is_string($url))
                    ->values();
                if ($sourceUrls->intersect($evidenceUrls)->isEmpty()) {
                    $this->error($errors, "{$path}.source_urls", $this->message('source_confirmed_requires_evidence'));
                }
            }
            if ($kind === IngredientValueProvenance::AiProposed
                && $confidence === IngredientEvidenceConfidence::Verified->value) {
                $this->error($errors, "{$path}.kind", $this->message('ai_proposed_verified'));
            }
            if ($kind === IngredientValueProvenance::AiProposed
                && in_array($field, ['proposal.soap_inci_naoh_name', 'proposal.soap_inci_koh_name'], true)
                && ! $this->hasReliableBaseIdentity($proposal, $fieldConfidence, $rows)) {
                $this->error($errors, "{$path}.kind", $this->message('soap_ai_requires_identity'));
            }
        }
    }

    /** @param array<string, mixed> $proposal @return list<string> */
    private function materialProposalFields(array $proposal): array
    {
        $fields = [
            'proposal.display_name',
            'proposal.inci_name',
            'proposal.category',
            'proposal.subcategory',
            'proposal.saponification_name',
            'proposal.soap_inci_naoh_name',
            'proposal.soap_inci_koh_name',
            'proposal.info_markdown',
            'proposal.soapmaking_relevant',
        ];

        foreach (['aliases', 'identifiers', 'cosing_functions', 'translations', 'market_labels'] as $collection) {
            foreach (is_array($proposal[$collection] ?? null) ? $proposal[$collection] : [] as $index => $_row) {
                $fields[] = "proposal.{$collection}.{$index}";
            }
        }

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $proposal
     * @param  array<int, array<string, mixed>>  $fieldConfidence
     * @param  array<int, array<string, mixed>>  $provenance
     */
    private function hasReliableBaseIdentity(array $proposal, array $fieldConfidence, array $provenance): bool
    {
        if (! is_string($proposal['inci_name'] ?? null) || trim($proposal['inci_name']) === '') {
            return false;
        }

        $confidenceRow = collect($fieldConfidence)->firstWhere('field', 'proposal.inci_name');
        $provenanceRow = collect($provenance)->firstWhere('field', 'proposal.inci_name');
        $confidence = is_array($confidenceRow) ? ($confidenceRow['confidence'] ?? null) : null;
        $inciProvenance = is_array($provenanceRow) ? ($provenanceRow['kind'] ?? null) : null;

        return in_array($confidence, [
            IngredientEvidenceConfidence::Verified->value,
            IngredientEvidenceConfidence::Supported->value,
        ], true) && $inciProvenance === IngredientValueProvenance::SourceConfirmed->value;
    }

    /**
     * @param  array<string, mixed>  $proposal
     * @param  array<int, array<string, mixed>>  $fieldConfidence
     * @param  array<int, array<string, mixed>>  $evidence
     * @param  array<int, array<string, mixed>>  $valueProvenance
     * @param  array<string, list<string>>  $errors
     */
    private function requireEvidenceForProposal(
        array $proposal,
        array $fieldConfidence,
        array $evidence,
        array $valueProvenance,
        array &$errors,
    ): void {
        $fields = ['proposal.inci_name'];
        foreach (['soap_inci_naoh_name', 'soap_inci_koh_name'] as $soapField) {
            if (filled($proposal[$soapField] ?? null)) {
                $fields[] = "proposal.{$soapField}";
            }
        }
        foreach ($proposal['aliases'] as $index => $_row) {
            $fields[] = "proposal.aliases.{$index}";
        }
        foreach ($proposal['identifiers'] as $index => $_row) {
            $fields[] = "proposal.identifiers.{$index}";
        }
        foreach ($proposal['cosing_functions'] as $index => $_row) {
            $fields[] = "proposal.cosing_functions.{$index}";
        }
        foreach ($proposal['market_labels'] as $index => $_row) {
            $fields[] = "proposal.market_labels.{$index}";
        }
        $evidenceFields = collect($evidence)
            ->pluck('field')
            ->filter(fn (mixed $field): bool => is_string($field))
            ->all();
        $unresolvedFields = collect($fieldConfidence)
            ->filter(fn (array $row): bool => ($row['confidence'] ?? null) === IngredientEvidenceConfidence::Unresolved->value)
            ->pluck('field')
            ->filter(fn (mixed $field): bool => is_string($field))
            ->all();
        $nonSourceProvenanceFields = collect($valueProvenance)
            ->filter(fn (mixed $row): bool => is_array($row)
                && in_array($row['kind'] ?? null, [
                    IngredientValueProvenance::AiProposed->value,
                    IngredientValueProvenance::ReviewerSupplied->value,
                    IngredientValueProvenance::Unresolved->value,
                ], true))
            ->pluck('field')
            ->filter(fn (mixed $field): bool => is_string($field))
            ->all();
        foreach ($fields as $field) {
            if (! in_array($field, $unresolvedFields, true)
                && ! in_array($field, $nonSourceProvenanceFields, true)
                && ! in_array($field, $evidenceFields, true)) {
                $this->error($errors, 'evidence', $this->message('evidence_missing', ['field' => $field]));
            }
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, list<string>>  $errors
     */
    private function validateSourceFields(array $row, string $path, array &$errors): void
    {
        if (! is_string($row['source_name'] ?? null) || trim($row['source_name']) === '') {
            $this->error($errors, "{$path}.source_name", $this->message('source_name'));
        }
        if (! $this->isHttpUrl($row['source_url'] ?? null)) {
            $this->error($errors, "{$path}.source_url", $this->message('source_url'));
        }
        $sourceTier = is_string($row['source_tier'] ?? null) ? IngredientSourceTier::tryFrom($row['source_tier']) : null;
        $confidence = is_string($row['confidence'] ?? null) ? IngredientEvidenceConfidence::tryFrom($row['confidence']) : null;
        if (! $sourceTier instanceof IngredientSourceTier) {
            $this->error($errors, "{$path}.source_tier", $this->message('source_tier'));
        }
        if (! $confidence instanceof IngredientEvidenceConfidence) {
            $this->error($errors, "{$path}.confidence", $this->message('evidence_confidence'));
        }
        if ($confidence === IngredientEvidenceConfidence::Verified && $sourceTier !== IngredientSourceTier::Official) {
            $this->error($errors, "{$path}.confidence", $this->message('verified_requires_official'));
        }
        if (($row['source_version'] ?? null) !== null && ! is_string($row['source_version'])) {
            $this->error($errors, "{$path}.source_version", $this->message('string_or_null'));
        }
        if (($row['source_updated_at'] ?? null) !== null && ! $this->isIsoDate($row['source_updated_at'])) {
            $this->error($errors, "{$path}.source_updated_at", $this->message('date_format'));
        }
        if (! $this->isIsoDateTime($row['retrieved_at'] ?? null)) {
            $this->error($errors, "{$path}.retrieved_at", $this->message('date_time_format'));
        }
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    private function validateStringList(mixed $value, string $path, array &$errors): void
    {
        if (! is_array($value)) {
            $this->error($errors, $path, $this->message('array'));

            return;
        }
        foreach ($value as $index => $item) {
            if (! is_string($item)) {
                $this->error($errors, "{$path}.{$index}", $this->message('string_list_item'));
            }
        }
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  list<string>  $allowed
     * @param  array<string, list<string>>  $errors
     */
    private function validateExactKeys(array $value, array $allowed, string $path, array &$errors): void
    {
        foreach (array_diff(array_keys($value), $allowed) as $unknown) {
            $this->error($errors, $path, $this->message('unknown_field', ['field' => $unknown]));
        }
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    private function error(array &$errors, string $path, string $message): void
    {
        $errors[$path] ??= [];
        $errors[$path][] = $message;
    }

    /**
     * @param  list<string>  $warnings
     */
    private function warnOnWordCount(string $value, string $path, array &$warnings): void
    {
        $wordCount = preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}’\'\-]*/u', $value);
        $wordCount = is_int($wordCount) ? $wordCount : 0;
        $minimum = (int) data_get(config('ingredient-enrichment.guidance'), 'minimum_words', 80);
        $maximum = (int) data_get(config('ingredient-enrichment.guidance'), 'maximum_words', 220);

        if ($wordCount < $minimum || $wordCount > $maximum) {
            $warnings[] = (string) __('ingredient_enrichment.warnings.word_count', [
                'path' => $path,
                'count' => $wordCount,
                'minimum' => $minimum,
                'maximum' => $maximum,
            ]);
        }
    }

    /**
     * @param  array<string, scalar>  $replace
     */
    private function message(string $key, array $replace = []): string
    {
        return (string) __("ingredient_enrichment.validation.{$key}", $replace);
    }

    private function isHttpUrl(mixed $value): bool
    {
        if (! is_string($value) || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        return in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true);
    }

    private function isOfficialCosingUrl(mixed $value): bool
    {
        $host = strtolower((string) parse_url((string) $value, PHP_URL_HOST));

        return $host === 'ec.europa.eu'
            || str_ends_with($host, '.ec.europa.eu')
            || $host === 'single-market-economy.ec.europa.eu';
    }

    private function isIsoDate(mixed $value): bool
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return false;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
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

    /** @return list<string> */
    private function sourceFieldKeys(): array
    {
        return [
            'source_name', 'source_url', 'source_tier', 'confidence', 'source_version', 'source_updated_at', 'retrieved_at',
        ];
    }

    /** @return list<string> */
    private function sourceStringFields(): array
    {
        return $this->sourceFieldKeys();
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
