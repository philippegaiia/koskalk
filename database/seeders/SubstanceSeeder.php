<?php

namespace Database\Seeders;

use App\Models\Allergen;
use App\Models\IngredientSubstanceEntry;
use App\Models\RegulatoryRegime;
use App\Models\RegulatoryRegimeSubstanceRule;
use App\Models\Substance;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;

class SubstanceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $sourceName = 'Platform starter substance catalog';
        $legacySourceName = 'Platform starter regulated substance watch list';
        $substances = [
            ['name' => 'Beta-asarone', 'entity_type' => 'constituent', 'synonyms' => ['β-Asarone']],
            ['name' => 'Furocoumarins', 'entity_type' => 'group', 'synonyms' => ['Furanocoumarins']],
            ['name' => 'Linalool', 'entity_type' => 'constituent', 'allergen_inci' => 'LINALOOL'],
            ['name' => 'Methyl eugenol', 'entity_type' => 'constituent'],
            ['name' => 'Pulegone', 'entity_type' => 'constituent'],
            ['name' => 'Safrole', 'entity_type' => 'constituent'],
        ];

        foreach ($substances as $substanceData) {
            $allergenInci = $substanceData['allergen_inci'] ?? null;
            $allergenId = is_string($allergenInci)
                ? Allergen::query()->where('inci_name', $allergenInci)->value('id')
                : null;

            $substance = $this->starterSubstance(
                $sourceName,
                $legacySourceName,
                $substanceData,
                is_numeric($allergenId) ? (int) $allergenId : null,
            );

            RegulatoryRegime::query()
                ->whereIn('code', ['eu', 'canada_2026', 'canada_expanded_preview', 'us_mocra_preview'])
                ->get()
                ->each(function (RegulatoryRegime $regime) use ($substance): void {
                    $rule = RegulatoryRegimeSubstanceRule::query()->firstOrNew([
                        'regulatory_regime_id' => $regime->id,
                        'substance_id' => $substance->id,
                    ]);

                    if ($rule->exists && $this->ruleHasSpecificComplianceData($rule)) {
                        return;
                    }

                    $rule->fill($this->starterRuleAttributes())->save();
                });
        }
    }

    /**
     * @param  array{name: string, entity_type: string, synonyms?: array<int, string>}  $substanceData
     */
    private function starterSubstance(string $sourceName, string $legacySourceName, array $substanceData, ?int $allergenId): Substance
    {
        $substances = Substance::query()
            ->where('name', $substanceData['name'])
            ->whereIn('source_name', [$sourceName, $legacySourceName])
            ->get()
            ->sortBy(fn (Substance $substance): string => ($substance->source_name === $sourceName ? '0' : '1').str_pad((string) $substance->id, 10, '0', STR_PAD_LEFT))
            ->values();

        $substance = $substances->first() ?? new Substance([
            'name' => $substanceData['name'],
        ]);

        $substance->fill([
            'source_name' => $sourceName,
            'entity_type' => $substanceData['entity_type'],
            'synonyms' => $substanceData['synonyms'] ?? null,
            'allergen_id' => $allergenId,
            'notes' => 'Starter substance catalog entry. Admin must attach and confirm exact market rules, limits, and sources before using it as a release decision.',
            'source_data' => [
                'seed_scope' => 'starter_substance_catalog',
            ],
        ])->save();

        $this->mergeDuplicateSubstances($substance, $substances->skip(1));

        return $substance;
    }

    /**
     * @param  Collection<int, Substance>  $duplicates
     */
    private function mergeDuplicateSubstances(Substance $canonical, Collection $duplicates): void
    {
        $duplicates->each(function (Substance $duplicate) use ($canonical): void {
            IngredientSubstanceEntry::query()
                ->where('substance_id', $duplicate->id)
                ->get()
                ->each(function (IngredientSubstanceEntry $entry) use ($canonical): void {
                    $canonicalEntry = IngredientSubstanceEntry::query()
                        ->where('ingredient_id', $entry->ingredient_id)
                        ->where('substance_id', $canonical->id)
                        ->first();

                    if ($canonicalEntry instanceof IngredientSubstanceEntry) {
                        $canonicalEntry->update($this->mergedIngredientEntryAttributes($canonicalEntry, $entry));
                        $entry->delete();

                        return;
                    }

                    $entry->update(['substance_id' => $canonical->id]);
                });

            RegulatoryRegimeSubstanceRule::query()
                ->where('substance_id', $duplicate->id)
                ->get()
                ->each(function (RegulatoryRegimeSubstanceRule $rule) use ($canonical): void {
                    $canonicalRule = RegulatoryRegimeSubstanceRule::query()
                        ->where('regulatory_regime_id', $rule->regulatory_regime_id)
                        ->where('substance_id', $canonical->id)
                        ->first();

                    if ($canonicalRule instanceof RegulatoryRegimeSubstanceRule) {
                        $canonicalRule->update($this->mergedRuleAttributes($canonicalRule, $rule));
                        $rule->delete();

                        return;
                    }

                    $rule->update(['substance_id' => $canonical->id]);
                });

            $duplicate->delete();
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function starterRuleAttributes(): array
    {
        return [
            'rule_type' => 'watch',
            'rinse_off_max_percent' => null,
            'leave_on_max_percent' => null,
            'threshold_operator' => 'less_than_or_equal',
            'exposure_scope' => 'both',
            'label_warning_text' => 'Check this substance against the supplier documents and current market rule.',
            'is_active' => true,
            'source_reference' => 'Platform starter rule. Replace with official source before relying on it.',
            'source_data' => [
                'seed_scope' => 'starter_rule',
            ],
        ];
    }

    private function ruleHasSpecificComplianceData(RegulatoryRegimeSubstanceRule $rule): bool
    {
        $sourceReference = str((string) $rule->source_reference)->lower();

        return $rule->rule_type !== 'watch'
            || $rule->rinse_off_max_percent !== null
            || $rule->leave_on_max_percent !== null
            || ($rule->source_reference !== null && ! $sourceReference->contains('starter'));
    }

    /**
     * @return array<string, mixed>
     */
    private function mergedIngredientEntryAttributes(IngredientSubstanceEntry $canonical, IngredientSubstanceEntry $duplicate): array
    {
        $canonicalConcentration = $canonical->concentration_percent === null ? null : (float) $canonical->concentration_percent;
        $duplicateConcentration = $duplicate->concentration_percent === null ? null : (float) $duplicate->concentration_percent;
        $useDuplicateConcentration = $duplicateConcentration !== null
            && ($canonicalConcentration === null || $duplicateConcentration > $canonicalConcentration);
        $useDuplicateSource = $duplicate->concentration_source !== 'unknown'
            || $canonical->concentration_source === 'unknown'
            || blank($canonical->concentration_source);

        return [
            'concentration_percent' => $useDuplicateConcentration
                ? $duplicate->concentration_percent
                : $canonical->concentration_percent,
            'concentration_source' => $useDuplicateSource
                ? $duplicate->concentration_source
                : $canonical->concentration_source,
            'source_notes' => filled($duplicate->source_notes)
                ? $duplicate->source_notes
                : $canonical->source_notes,
            'source_data' => array_replace_recursive(
                is_array($canonical->source_data) ? $canonical->source_data : [],
                is_array($duplicate->source_data) ? $duplicate->source_data : [],
            ) ?: null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mergedRuleAttributes(RegulatoryRegimeSubstanceRule $canonical, RegulatoryRegimeSubstanceRule $duplicate): array
    {
        $useDuplicateRule = $this->ruleRank((string) $duplicate->rule_type) > $this->ruleRank((string) $canonical->rule_type);

        return [
            'rule_type' => $useDuplicateRule ? $duplicate->rule_type : $canonical->rule_type,
            'rinse_off_max_percent' => $this->stricterLimit($canonical->rinse_off_max_percent, $duplicate->rinse_off_max_percent),
            'leave_on_max_percent' => $this->stricterLimit($canonical->leave_on_max_percent, $duplicate->leave_on_max_percent),
            'threshold_operator' => $useDuplicateRule ? $duplicate->threshold_operator : $canonical->threshold_operator,
            'exposure_scope' => $useDuplicateRule ? $duplicate->exposure_scope : $canonical->exposure_scope,
            'label_warning_text' => filled($duplicate->label_warning_text) ? $duplicate->label_warning_text : $canonical->label_warning_text,
            'is_active' => $canonical->is_active || $duplicate->is_active,
            'effective_from' => $duplicate->effective_from ?? $canonical->effective_from,
            'effective_until' => $duplicate->effective_until ?? $canonical->effective_until,
            'source_reference' => filled($duplicate->source_reference) ? $duplicate->source_reference : $canonical->source_reference,
            'source_data' => array_replace_recursive(
                is_array($canonical->source_data) ? $canonical->source_data : [],
                is_array($duplicate->source_data) ? $duplicate->source_data : [],
            ) ?: null,
        ];
    }

    private function stricterLimit(mixed $canonicalLimit, mixed $duplicateLimit): mixed
    {
        if ($canonicalLimit === null || $canonicalLimit === '') {
            return $duplicateLimit;
        }

        if ($duplicateLimit === null || $duplicateLimit === '') {
            return $canonicalLimit;
        }

        return min((float) $canonicalLimit, (float) $duplicateLimit);
    }

    private function ruleRank(string $ruleType): int
    {
        return match ($ruleType) {
            'prohibited' => 3,
            'restricted' => 2,
            'watch' => 1,
            default => 0,
        };
    }
}
