<?php

namespace App\Services\IngredientEnrichment;

use App\Enums\IngredientEnrichmentReplaceField;
use App\Enums\IngredientSubcategory;
use App\Models\Ingredient;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class IngredientEnrichmentPlanner
{
    public function __construct(
        private readonly IngredientEnrichmentSnapshotBuilder $snapshotBuilder,
    ) {}

    /**
     * @param  array<string, mixed>  $result
     * @param  array<int, IngredientEnrichmentReplaceField|string>  $replace
     * @return array{ingredient_id:?int, catalog_key:?string, source_fingerprint:string, effective:array<string,mixed>, decisions:list<array{field:string,decision:string,current:mixed,proposed:mixed}>, warnings:list<string>, errors:array<string,list<string>>, changed:bool}
     */
    public function plan(Ingredient $ingredient, array $result, array $replace = []): array
    {
        $replaceFields = $this->normalizeReplaceFields($replace);
        $current = $this->snapshotBuilder->snapshot($ingredient);
        $proposal = is_array($result['proposal'] ?? null) ? $result['proposal'] : [];
        $decisions = [];
        $warnings = [];
        $errors = [];
        $effective = $current;

        foreach (['display_name', 'inci_name', 'category', 'subcategory', 'saponification_name', 'soap_inci_naoh_name', 'soap_inci_koh_name', 'info_markdown'] as $field) {
            $currentValue = data_get($current, "canonical.{$field}");
            $proposedValue = array_key_exists($field, $proposal) ? $proposal[$field] : null;
            $fieldDecision = $this->scalarDecision($currentValue, $proposedValue, in_array($field, $replaceFields, true));
            $decisions[] = [
                'field' => "proposal.{$field}",
                'decision' => $fieldDecision['decision'],
                'current' => $currentValue,
                'proposed' => $proposedValue,
            ];
            data_set($effective, "canonical.{$field}", $fieldDecision['effective']);
        }

        $effectiveCategory = $effective['canonical']['category'] ?? null;
        $effectiveSubcategory = $effective['canonical']['subcategory'] ?? null;
        if (! $this->isCompatibleTaxonomy($effectiveCategory, $effectiveSubcategory)) {
            if (in_array('category', $replaceFields, true)) {
                $effectiveCategory = $current['canonical']['category'] ?? null;
                $warnings[] = (string) __('ingredient_enrichment.warnings.category_requires_subcategory');
                $this->markDecisionWarning($decisions, 'proposal.category');
            } else {
                $effectiveSubcategory = $current['canonical']['subcategory'] ?? null;
                $warnings[] = (string) __('ingredient_enrichment.warnings.subcategory_incompatible');
                $this->markDecisionWarning($decisions, 'proposal.subcategory');
            }
        }
        data_set($effective, 'canonical.category', $effectiveCategory);
        data_set($effective, 'canonical.subcategory', $effectiveSubcategory);

        foreach ([
            'aliases' => 'proposal.aliases',
            'identifiers' => 'proposal.identifiers',
            'cosing_functions' => 'proposal.cosing_functions',
            'translations' => 'proposal.translations',
            'market_labels' => 'proposal.market_labels',
        ] as $field => $path) {
            $currentRows = is_array($current[$field] ?? null) ? $current[$field] : [];
            $proposedRows = is_array($proposal[$field] ?? null) ? $proposal[$field] : [];
            $replaceCollection = in_array($field, $replaceFields, true);
            $collection = $replaceCollection
                ? $this->replaceRows($field, $currentRows, $proposedRows)
                : $this->mergeRows($field, $currentRows, $proposedRows);
            $decision = $this->collectionDecision($currentRows, $collection, $replaceCollection);

            $decisions[] = [
                'field' => $path,
                'decision' => $decision,
                'current' => $currentRows,
                'proposed' => $proposedRows,
            ];
            if ($field === 'translations') {
                $decisions = [
                    ...$decisions,
                    ...$this->translationDecisions($currentRows, $proposedRows, $replaceCollection),
                ];
            }
            if (in_array($field, ['cosing_functions', 'market_labels'], true)) {
                $decisions = [
                    ...$decisions,
                    ...$this->sourceBackedRowDecisions($field, $currentRows, $proposedRows),
                ];
            }
            $effective[$field] = $collection;
        }

        return [
            'ingredient_id' => $ingredient->exists ? (int) $ingredient->id : null,
            'catalog_key' => $ingredient->catalog_key === null ? null : (string) $ingredient->catalog_key,
            'source_fingerprint' => (string) ($result['source_fingerprint'] ?? ''),
            'effective' => $effective,
            'decisions' => $decisions,
            'warnings' => $warnings,
            'errors' => $errors,
            'changed' => array_any($decisions, fn (array $decision): bool => in_array($decision['decision'], ['new', 'replace'], true)),
        ];
    }

    /**
     * Build a review plan for an intake row that has not been promoted yet.
     * A linked existing ingredient is planned against its current catalogue
     * state; a distinct material is planned against the submitted identity.
     *
     * @param  array<string, mixed>  $result
     * @param  list<IngredientEnrichmentReplaceField|string>  $replace
     * @return array<string, mixed>
     */
    public function planForIntake(
        array $result,
        ?Ingredient $linkedIngredient = null,
        ?string $currentName = null,
        ?string $currentInci = null,
        array $replace = [],
    ): array {
        if ($linkedIngredient instanceof Ingredient) {
            return $this->plan($linkedIngredient, $result, $replace);
        }

        $virtual = new Ingredient([
            'catalog_key' => null,
            'display_name' => $currentName,
            'inci_name' => $currentInci,
            'category' => null,
            'subcategory' => null,
            'is_active' => false,
            'is_manufactured' => false,
            'requires_aromatic_compliance' => false,
            'source_data' => null,
        ]);

        return $this->plan($virtual, $result, $replace);
    }

    /**
     * @param  array<int, IngredientEnrichmentReplaceField|string>  $replace
     * @return list<string>
     */
    public function normalizeReplaceFields(array $replace): array
    {
        $fields = [];
        foreach ($replace as $value) {
            $field = $value instanceof IngredientEnrichmentReplaceField
                ? $value
                : IngredientEnrichmentReplaceField::tryFromMixed($value);

            if (! $field instanceof IngredientEnrichmentReplaceField) {
                throw ValidationException::withMessages([
                    'replace' => __('ingredient_enrichment.replacement.unknown', ['field' => $value]),
                ]);
            }

            $fields[] = $field->value;
        }

        return array_values(array_unique($fields));
    }

    /**
     * @return array{decision:string,effective:mixed}
     */
    private function scalarDecision(mixed $current, mixed $proposed, bool $replace): array
    {
        if ($current === $proposed) {
            return ['decision' => 'unchanged', 'effective' => $current];
        }

        if ($this->emptyValue($current)) {
            return ['decision' => 'new', 'effective' => $proposed];
        }

        return [
            'decision' => $replace ? 'replace' : 'preserved',
            'effective' => $replace ? $proposed : $current,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $current
     * @param  list<array<string,mixed>>  $proposed
     * @return list<array<string,mixed>>
     */
    private function mergeRows(string $field, array $current, array $proposed): array
    {
        if ($field === 'translations') {
            $byLocale = collect($current)->keyBy('locale');
            foreach ($proposed as $row) {
                $locale = (string) ($row['locale'] ?? '');
                $existing = $byLocale->get($locale, []);
                $byLocale->put($locale, [
                    'locale' => $locale,
                    'display_name' => $this->emptyValue($existing['display_name'] ?? null)
                        ? ($row['display_name'] ?? null)
                        : $existing['display_name'],
                    'saponification_name' => $this->emptyValue($existing['saponification_name'] ?? null)
                        ? ($row['saponification_name'] ?? null)
                        : $existing['saponification_name'],
                    'info_markdown' => $this->emptyValue($existing['info_markdown'] ?? null)
                        ? ($row['info_markdown'] ?? null)
                        : $existing['info_markdown'],
                ]);
            }

            return $byLocale->sortKeys()->values()->all();
        }

        $key = match ($field) {
            'aliases' => fn (array $row): string => implode('|', [
                (string) ($row['locale'] ?? ''),
                mb_strtolower((string) ($row['normalized_name'] ?? $row['name'] ?? '')),
            ]),
            'identifiers' => fn (array $row): string => implode('|', [(string) ($row['scheme'] ?? ''), strtoupper((string) ($row['normalized_value'] ?? $row['value'] ?? ''))]),
            'cosing_functions' => fn (array $row): string => (string) ($row['key'] ?? ''),
            'market_labels' => fn (array $row): string => (string) ($row['market_code'] ?? ''),
            default => fn (array $row): string => json_encode($row, JSON_THROW_ON_ERROR),
        };
        $merged = collect($current)->keyBy($key);
        foreach ($proposed as $row) {
            $rowKey = $key($row);
            if ($field === 'cosing_functions') {
                $merged->put($rowKey, [
                    'key' => $rowKey,
                    'source_reference' => (string) ($row['source_url'] ?? ''),
                    'source_checked_at' => (string) ($row['checked_at'] ?? ''),
                ]);
            } elseif ($field === 'market_labels' || ! $merged->has($rowKey)) {
                $merged->put($rowKey, $row);
            }
        }

        return $merged->sortKeys()->values()->all();
    }

    private function collectionDecision(array $current, array $effective, bool $replace): string
    {
        if ($current === $effective) {
            return 'unchanged';
        }

        return $replace ? 'replace' : (count($effective) > count($current) ? 'new' : 'preserved');
    }

    /**
     * @param  list<array<string,mixed>>  $current
     * @param  list<array<string,mixed>>  $proposed
     * @return list<array{field:string,decision:string,current:mixed,proposed:mixed}>
     */
    private function translationDecisions(array $current, array $proposed, bool $replace): array
    {
        $currentByLocale = collect($current)->keyBy('locale');
        $proposedByLocale = collect($proposed)->keyBy('locale');
        $locales = $proposedByLocale
            ->keys()
            ->sort()
            ->values();
        $decisions = [];

        foreach ($locales as $locale) {
            $currentRow = $currentByLocale->get($locale, []);
            $proposedRow = $proposedByLocale->get($locale, []);

            foreach (['display_name', 'saponification_name', 'info_markdown'] as $field) {
                $currentValue = is_array($currentRow) ? ($currentRow[$field] ?? null) : null;
                $proposedValue = is_array($proposedRow) ? ($proposedRow[$field] ?? null) : null;
                $decision = $this->scalarDecision($currentValue, $proposedValue, $replace);

                $decisions[] = [
                    'field' => "proposal.translations.{$locale}.{$field}",
                    'decision' => $decision['decision'],
                    'current' => $currentValue,
                    'proposed' => $proposedValue,
                ];
            }
        }

        return $decisions;
    }

    /**
     * Source-backed rows with an existing stable key are updated when explicitly proposed.
     * This mirrors the merge behavior of the COSING and market-label domain services.
     *
     * @param  list<array<string,mixed>>  $current
     * @param  list<array<string,mixed>>  $proposed
     * @return list<array{field:string,decision:string,current:mixed,proposed:mixed}>
     */
    private function sourceBackedRowDecisions(string $field, array $current, array $proposed): array
    {
        $key = $field === 'cosing_functions'
            ? fn (array $row): string => (string) ($row['key'] ?? '')
            : fn (array $row): string => (string) ($row['market_code'] ?? '');
        $currentByKey = collect($current)->keyBy($key);
        $decisions = [];

        foreach ($proposed as $row) {
            $rowKey = $key($row);
            $currentRow = $currentByKey->get($rowKey);
            $path = $field === 'cosing_functions'
                ? "proposal.cosing_functions.{$rowKey}"
                : "proposal.market_labels.{$rowKey}";

            if (! is_array($currentRow)) {
                $decisions[] = [
                    'field' => $path,
                    'decision' => 'new',
                    'current' => null,
                    'proposed' => $row,
                ];

                continue;
            }

            $same = $this->sourceBackedRowsMatch($field, $currentRow, $row);
            $decisions[] = [
                'field' => $path,
                'decision' => $same ? 'unchanged' : 'replace',
                'current' => $currentRow,
                'proposed' => $row,
            ];
        }

        return $decisions;
    }

    private function sourceBackedRowsMatch(string $field, array $current, array $proposed): bool
    {
        if ($field === 'cosing_functions') {
            return (string) ($current['key'] ?? '') === (string) ($proposed['key'] ?? '')
                && (string) ($current['source_reference'] ?? '') === (string) ($proposed['source_url'] ?? '')
                && $this->dateOnly($current['source_checked_at'] ?? null) === $this->dateOnly($proposed['checked_at'] ?? null);
        }

        foreach (['market_code', 'declaration_name', 'source_name', 'source_url', 'effective_from', 'effective_until'] as $fieldName) {
            if ((string) ($current[$fieldName] ?? '') !== (string) ($proposed[$fieldName] ?? '')) {
                return false;
            }
        }

        return $this->dateOnly($current['reviewed_at'] ?? null) === $this->dateOnly($proposed['reviewed_at'] ?? null);
    }

    private function dateOnly(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $value)->toDateString();
        } catch (\Throwable) {
            return trim((string) $value);
        }
    }

    /**
     * Explicit replacement reconciles configured translation locales while preserving future locales.
     *
     * @param  list<array<string,mixed>>  $current
     * @param  list<array<string,mixed>>  $proposed
     * @return list<array<string,mixed>>
     */
    private function replaceRows(string $field, array $current, array $proposed): array
    {
        if ($field !== 'translations') {
            return $proposed;
        }

        $configuredLocales = $this->snapshotBuilder->targetLocales();
        $proposedByLocale = collect($proposed)->keyBy('locale');
        $currentOutsideCatalogue = collect($current)
            ->filter(fn (array $row): bool => ! in_array((string) ($row['locale'] ?? ''), $configuredLocales, true));

        return $currentOutsideCatalogue
            ->merge($proposedByLocale)
            ->sortBy('locale')
            ->values()
            ->all();
    }

    private function emptyValue(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '') || $value === [];
    }

    private function isCompatibleTaxonomy(mixed $category, mixed $subcategory): bool
    {
        if (! is_string($category) || $category === '') {
            return false;
        }

        if ($category === 'other') {
            return $subcategory === null;
        }

        $candidate = is_string($subcategory) ? IngredientSubcategory::tryFrom($subcategory) : null;

        return $candidate instanceof IngredientSubcategory && $candidate->category()->value === $category;
    }

    /**
     * @param  list<array{field:string,decision:string,current:mixed,proposed:mixed}>  $decisions
     */
    private function markDecisionWarning(array &$decisions, string $field): void
    {
        $index = array_search($field, array_column($decisions, 'field'), true);

        if (is_int($index)) {
            $decisions[$index]['decision'] = 'warning';
        }
    }
}
