<?php

namespace App\Services\IngredientEnrichment;

use App\DecimalStringFormatter;
use App\Enums\IngredientCategory;
use App\Enums\IngredientFunctionSource;
use App\Enums\IngredientLabelMarket;
use App\Enums\IngredientSubcategory;
use App\Models\Ingredient;
use App\Models\IngredientFattyAcid;
use App\Models\IngredientFunction;
use App\Models\IngredientMarketLabel;
use Carbon\CarbonImmutable;

class IngredientEnrichmentSnapshotBuilder
{
    public function __construct(private readonly DecimalStringFormatter $decimalStringFormatter) {}

    /**
     * Build the normalized state and its canonical fingerprint.
     *
     * @return array{snapshot: array<string, mixed>, state: array<string, mixed>, canonical_json: string, fingerprint: string}
     */
    public function build(Ingredient $ingredient): array
    {
        $snapshot = $this->snapshot($ingredient);
        $canonicalJson = $this->canonicalJson($snapshot);

        return [
            'snapshot' => $snapshot,
            'state' => $snapshot,
            'canonical_json' => $canonicalJson,
            'fingerprint' => hash('sha256', $canonicalJson),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(Ingredient $ingredient): array
    {
        $ingredient->loadMissing([
            'identifiers',
            'aliases',
            'functions',
            'translations',
            'marketLabels',
            'sapProfile',
            'fattyAcidEntries.fattyAcid',
        ]);

        $soapChemistry = $this->trustedSoapChemistry($ingredient);

        return [
            'catalog_key' => (string) $ingredient->catalog_key,
            'canonical' => [
                'display_name' => $this->nullableString($ingredient->display_name),
                'inci_name' => $this->nullableString($ingredient->inci_name),
                'category' => $this->enumValue($ingredient->category),
                'subcategory' => $this->enumValue($ingredient->subcategory),
                'saponification_name' => $this->nullableString($ingredient->saponification_name),
                'soap_inci_naoh_name' => $this->nullableString($ingredient->soap_inci_naoh_name),
                'soap_inci_koh_name' => $this->nullableString($ingredient->soap_inci_koh_name),
                'info_markdown' => $this->nullableString($ingredient->info_markdown),
                'cosing_reference' => $this->nullableString($ingredient->cosing_reference),
                'is_active' => (bool) $ingredient->is_active,
                'is_manufactured' => (bool) $ingredient->is_manufactured,
                'requires_aromatic_compliance' => (bool) $ingredient->requires_aromatic_compliance,
            ],
            ...($soapChemistry === null ? [] : ['soap_chemistry' => $soapChemistry]),
            'identifiers' => $ingredient->identifiers
                ->map(fn ($identifier): array => [
                    'scheme' => $this->enumValue($identifier->scheme),
                    'value' => (string) $identifier->value,
                    'normalized_value' => (string) $identifier->normalized_value,
                    'is_primary' => (bool) $identifier->is_primary,
                ])
                ->sortBy(fn (array $identifier): string => implode('|', [
                    (string) $identifier['scheme'],
                    (string) $identifier['normalized_value'],
                    $identifier['is_primary'] ? '1' : '0',
                ]))
                ->values()
                ->all(),
            'aliases' => $ingredient->aliases
                ->map(fn ($alias): array => [
                    'locale' => (string) $alias->locale,
                    'name' => (string) $alias->name,
                    'normalized_name' => (string) $alias->normalized_name,
                    'kind' => $this->enumValue($alias->kind),
                ])
                ->sortBy(fn (array $alias): string => implode('|', [
                    (string) $alias['locale'],
                    (string) $alias['normalized_name'],
                    (string) $alias['kind'],
                ]))
                ->values()
                ->all(),
            'cosing_functions' => $ingredient->functions
                ->filter(fn (IngredientFunction $function): bool => $function->pivot?->source === IngredientFunctionSource::CosIng->value)
                ->map(fn (IngredientFunction $function): array => [
                    'key' => (string) $function->key,
                    'source_reference' => $this->nullableString($function->pivot?->source_reference),
                    'source_checked_at' => $this->dateTimeString($function->pivot?->source_checked_at),
                ])
                ->sortBy('key')
                ->values()
                ->all(),
            'translations' => $ingredient->translations
                ->map(fn ($translation): array => [
                    'locale' => (string) $translation->locale,
                    'display_name' => $this->nullableString($translation->display_name),
                    'saponification_name' => $this->nullableString($translation->saponification_name),
                    'info_markdown' => $this->nullableString($translation->info_markdown),
                ])
                ->sortBy('locale')
                ->values()
                ->all(),
            'market_labels' => $ingredient->marketLabels
                ->map(fn (IngredientMarketLabel $label): array => [
                    'market_code' => $this->enumValue($label->market_code),
                    'declaration_name' => (string) $label->declaration_name,
                    'source_name' => (string) $label->source_name,
                    'source_url' => (string) $label->source_url,
                    'effective_from' => $label->effective_from?->toDateString(),
                    'effective_until' => $label->effective_until?->toDateString(),
                    'reviewed_at' => $this->dateTimeString($label->reviewed_at),
                ])
                ->sortBy(fn (array $label): string => implode('|', [
                    (string) $label['market_code'],
                    (string) $label['declaration_name'],
                    (string) $label['effective_from'],
                ]))
                ->values()
                ->all(),
        ];
    }

    public function fingerprint(Ingredient $ingredient): string
    {
        return $this->build($ingredient)['fingerprint'];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function canonicalJson(array $snapshot): string
    {
        return json_encode(
            $this->sortAssociative($snapshot),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    public function isIncomplete(Ingredient $ingredient): bool
    {
        $snapshot = $this->snapshot($ingredient);
        $canonical = is_array($snapshot['canonical'] ?? null) ? $snapshot['canonical'] : [];
        $category = IngredientCategory::tryFrom((string) ($canonical['category'] ?? ''));
        $subcategory = IngredientSubcategory::tryFrom((string) ($canonical['subcategory'] ?? ''));

        if ($this->nullableString($canonical['inci_name'] ?? null) === null) {
            return true;
        }

        if (! $category instanceof IngredientCategory) {
            return true;
        }

        if (($category === IngredientCategory::Other && $subcategory !== null)
            || ($category !== IngredientCategory::Other
                && (! $subcategory instanceof IngredientSubcategory || $subcategory->category() !== $category))) {
            return true;
        }

        if (! $this->hasGuidanceHeadings($this->nullableString($canonical['info_markdown'] ?? null))) {
            return true;
        }

        $soapmakingRelevant = $this->hasHeading(
            $this->nullableString($canonical['info_markdown'] ?? null),
            (string) data_get(config('ingredient-enrichment.guidance'), 'soapmaking_heading', 'Soapmaking'),
        );

        $translations = collect($snapshot['translations'] ?? [])->keyBy('locale');
        foreach ($this->targetLocales() as $locale) {
            $translation = $translations->get($locale);

            if (! is_array($translation)
                || $this->nullableString($translation['display_name'] ?? null) === null
                || ! $this->hasTranslatedGuidanceHeadings(
                    $this->nullableString($translation['info_markdown'] ?? null),
                    $locale,
                    $soapmakingRelevant,
                )) {
                return true;
            }
        }

        if ($category === IngredientCategory::Colourants && ! $this->hasCurrentColourLabels($snapshot['market_labels'] ?? [])) {
            return true;
        }

        $storedResultFingerprint = data_get($ingredient->source_data, 'enrichment.core.result_fingerprint');

        return filled($storedResultFingerprint) && $storedResultFingerprint !== $this->fingerprint($ingredient);
    }

    /**
     * @return list<string>
     */
    public function targetLocales(): array
    {
        return array_values(config('interface-translations.catalogue_locales', []));
    }

    /**
     * @return array<string, mixed>
     */
    private function sortAssociative(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->sortAssociative($item);
            }
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    private function enumValue(mixed $value): ?string
    {
        return $value instanceof \BackedEnum ? (string) $value->value : ($value === null ? null : (string) $value);
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @return array{
     *     koh_sap_value: string,
     *     naoh_sap_value: string,
     *     iodine_value: string|null,
     *     ins_value: string|null,
     *     fatty_acids: list<array{key: string, name: string, saturation_class: string|null, percentage: string}>
     * }|null
     */
    private function trustedSoapChemistry(Ingredient $ingredient): ?array
    {
        if (! $ingredient->canDriveSoapSaponification() || $ingredient->sapProfile === null) {
            return null;
        }

        return [
            'koh_sap_value' => (string) $ingredient->sapProfile->koh_sap_value,
            'naoh_sap_value' => $this->decimalStringFormatter->toFixed(
                (string) $ingredient->sapProfile->naoh_sap_value,
                6,
            ),
            'iodine_value' => $this->nullableString($ingredient->sapProfile->iodine_value),
            'ins_value' => $this->nullableString($ingredient->sapProfile->ins_value),
            'fatty_acids' => $ingredient->fattyAcidEntries
                ->filter(fn (IngredientFattyAcid $entry): bool => $entry->fattyAcid !== null)
                ->sortBy(fn (IngredientFattyAcid $entry): string => implode('|', [
                    str_pad((string) $entry->fattyAcid->display_order, 10, '0', STR_PAD_LEFT),
                    $entry->fattyAcid->key,
                ]))
                ->map(fn (IngredientFattyAcid $entry): array => [
                    'key' => (string) $entry->fattyAcid->key,
                    'name' => (string) $entry->fattyAcid->name,
                    'saturation_class' => $this->nullableString($entry->fattyAcid->saturation_class),
                    'percentage' => (string) $entry->percentage,
                ])
                ->values()
                ->all(),
        ];
    }

    private function dateTimeString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($value)->toIso8601String();
        }

        return $this->nullableString($value);
    }

    private function hasGuidanceHeadings(?string $guidance): bool
    {
        if ($guidance === null) {
            return false;
        }

        preg_match_all('/^##\s+(.+)$/m', $guidance, $matches);
        $headings = collect($matches[1] ?? [])
            ->map(fn (mixed $heading): string => trim((string) $heading))
            ->values()
            ->all();
        $required = data_get(config('ingredient-enrichment.guidance'), 'required_headings', []);
        $soapmakingHeading = (string) data_get(config('ingredient-enrichment.guidance'), 'soapmaking_heading', 'Soapmaking');
        $expected = $this->hasHeading($guidance, $soapmakingHeading)
            ? [...$required, $soapmakingHeading]
            : $required;

        return $headings === $expected;
    }

    private function hasTranslatedGuidanceHeadings(?string $guidance, string $locale, bool $soapmakingRelevant): bool
    {
        if ($guidance === null) {
            return false;
        }

        preg_match_all('/^##\s+(.+)$/m', $guidance, $matches);
        $localized = data_get(config('ingredient-enrichment.guidance'), "localized_headings.{$locale}", []);
        $expected = collect([
            $localized['overview'] ?? null,
            $localized['formulation_use'] ?? null,
            $soapmakingRelevant ? ($localized['soapmaking'] ?? null) : null,
        ])->filter(fn (mixed $heading): bool => is_string($heading) && $heading !== '')->values()->all();

        return array_map('trim', $matches[1] ?? []) === $expected;
    }

    private function hasHeading(?string $guidance, string $heading): bool
    {
        if ($guidance === null) {
            return false;
        }

        preg_match_all('/^##\s+(.+)$/m', $guidance, $matches);

        return in_array($heading, array_map('trim', $matches[1] ?? []), true);
    }

    private function hasCurrentColourLabels(mixed $rows): bool
    {
        $today = CarbonImmutable::today()->toDateString();
        $current = collect(is_array($rows) ? $rows : [])
            ->filter(fn (mixed $row): bool => is_array($row))
            ->filter(function (array $row) use ($today): bool {
                $from = $row['effective_from'] ?? null;
                $until = $row['effective_until'] ?? null;

                return ($from === null || $from <= $today) && ($until === null || $until >= $today);
            })
            ->keyBy('market_code');

        foreach (IngredientLabelMarket::cases() as $market) {
            $row = $current->get($market->value);

            if (! is_array($row) || $this->nullableString($row['declaration_name'] ?? null) === null) {
                return false;
            }

            if ($market === IngredientLabelMarket::Us && preg_match('/^CI\s*[0-9]{5}$/i', (string) $row['declaration_name']) === 1) {
                return false;
            }
        }

        return true;
    }
}
