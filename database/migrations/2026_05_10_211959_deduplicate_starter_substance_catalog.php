<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('substance_catalog')) {
            return;
        }

        $sourceName = 'Platform starter substance catalog';
        $legacySourceName = 'Platform starter regulated substance watch list';
        $substanceNames = [
            'Beta-asarone',
            'Furocoumarins',
            'Linalool',
            'Methyl eugenol',
            'Pulegone',
            'Safrole',
        ];

        foreach ($substanceNames as $substanceName) {
            $substances = DB::table('substance_catalog')
                ->where('name', $substanceName)
                ->whereIn('source_name', [$sourceName, $legacySourceName])
                ->orderByRaw('case when source_name = ? then 0 else 1 end, id', [$sourceName])
                ->get(['id']);

            $canonical = $substances->first();

            if ($canonical === null) {
                continue;
            }

            $canonicalId = (int) $canonical->id;

            DB::table('substance_catalog')
                ->where('id', $canonicalId)
                ->update([
                    'source_name' => $sourceName,
                    'updated_at' => now(),
                ]);

            foreach ($substances->skip(1) as $duplicate) {
                $this->mergeDuplicateSubstance((int) $duplicate->id, $canonicalId);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {}

    private function mergeDuplicateSubstance(int $duplicateId, int $canonicalId): void
    {
        if (Schema::hasTable('ingredient_substance_entries')) {
            DB::table('ingredient_substance_entries')
                ->where('substance_id', $duplicateId)
                ->orderBy('id')
                ->get(['id', 'ingredient_id', 'concentration_percent', 'concentration_source', 'source_notes', 'source_data'])
                ->each(function (object $entry) use ($canonicalId): void {
                    $canonicalEntry = DB::table('ingredient_substance_entries')
                        ->where('ingredient_id', $entry->ingredient_id)
                        ->where('substance_id', $canonicalId)
                        ->first(['id', 'concentration_percent', 'concentration_source', 'source_notes', 'source_data']);

                    if ($canonicalEntry !== null) {
                        DB::table('ingredient_substance_entries')
                            ->where('id', $canonicalEntry->id)
                            ->update($this->mergedIngredientEntryAttributes($canonicalEntry, $entry));

                        DB::table('ingredient_substance_entries')
                            ->where('id', $entry->id)
                            ->delete();

                        return;
                    }

                    DB::table('ingredient_substance_entries')
                        ->where('id', $entry->id)
                        ->update([
                            'substance_id' => $canonicalId,
                            'updated_at' => now(),
                        ]);
                });
        }

        if (Schema::hasTable('regulatory_regime_substance_rules')) {
            DB::table('regulatory_regime_substance_rules')
                ->where('substance_id', $duplicateId)
                ->orderBy('id')
                ->get([
                    'id',
                    'regulatory_regime_id',
                    'rule_type',
                    'rinse_off_max_percent',
                    'leave_on_max_percent',
                    'threshold_operator',
                    'exposure_scope',
                    'label_warning_text',
                    'is_active',
                    'effective_from',
                    'effective_until',
                    'source_reference',
                    'source_data',
                ])
                ->each(function (object $rule) use ($canonicalId): void {
                    $canonicalRule = DB::table('regulatory_regime_substance_rules')
                        ->where('regulatory_regime_id', $rule->regulatory_regime_id)
                        ->where('substance_id', $canonicalId)
                        ->first([
                            'id',
                            'rule_type',
                            'rinse_off_max_percent',
                            'leave_on_max_percent',
                            'threshold_operator',
                            'exposure_scope',
                            'label_warning_text',
                            'is_active',
                            'effective_from',
                            'effective_until',
                            'source_reference',
                            'source_data',
                        ]);

                    if ($canonicalRule !== null) {
                        DB::table('regulatory_regime_substance_rules')
                            ->where('id', $canonicalRule->id)
                            ->update($this->mergedRuleAttributes($canonicalRule, $rule));

                        DB::table('regulatory_regime_substance_rules')
                            ->where('id', $rule->id)
                            ->delete();

                        return;
                    }

                    DB::table('regulatory_regime_substance_rules')
                        ->where('id', $rule->id)
                        ->update([
                            'substance_id' => $canonicalId,
                            'updated_at' => now(),
                        ]);
                });
        }

        DB::table('substance_catalog')
            ->where('id', $duplicateId)
            ->delete();
    }

    /**
     * @return array<string, mixed>
     */
    private function mergedIngredientEntryAttributes(object $canonical, object $duplicate): array
    {
        $canonicalConcentration = $canonical->concentration_percent === null ? null : (float) $canonical->concentration_percent;
        $duplicateConcentration = $duplicate->concentration_percent === null ? null : (float) $duplicate->concentration_percent;
        $useDuplicateConcentration = $duplicateConcentration !== null
            && ($canonicalConcentration === null || $duplicateConcentration > $canonicalConcentration);
        $canonicalSource = (string) ($canonical->concentration_source ?? 'unknown');
        $duplicateSource = (string) ($duplicate->concentration_source ?? 'unknown');
        $useDuplicateSource = $duplicateSource !== 'unknown'
            || $canonicalSource === 'unknown'
            || trim($canonicalSource) === '';

        return [
            'concentration_percent' => $useDuplicateConcentration
                ? $duplicate->concentration_percent
                : $canonical->concentration_percent,
            'concentration_source' => $useDuplicateSource ? $duplicateSource : $canonicalSource,
            'source_notes' => filled($duplicate->source_notes ?? null)
                ? $duplicate->source_notes
                : $canonical->source_notes,
            'source_data' => $this->jsonValue(array_replace_recursive(
                $this->jsonArray($canonical->source_data ?? null),
                $this->jsonArray($duplicate->source_data ?? null),
            )),
            'updated_at' => now(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mergedRuleAttributes(object $canonical, object $duplicate): array
    {
        $useDuplicateRule = $this->ruleRank((string) $duplicate->rule_type) > $this->ruleRank((string) $canonical->rule_type);

        return [
            'rule_type' => $useDuplicateRule ? $duplicate->rule_type : $canonical->rule_type,
            'rinse_off_max_percent' => $this->stricterLimit($canonical->rinse_off_max_percent, $duplicate->rinse_off_max_percent),
            'leave_on_max_percent' => $this->stricterLimit($canonical->leave_on_max_percent, $duplicate->leave_on_max_percent),
            'threshold_operator' => $useDuplicateRule ? $duplicate->threshold_operator : $canonical->threshold_operator,
            'exposure_scope' => $useDuplicateRule ? $duplicate->exposure_scope : $canonical->exposure_scope,
            'label_warning_text' => filled($duplicate->label_warning_text ?? null)
                ? $duplicate->label_warning_text
                : $canonical->label_warning_text,
            'is_active' => (bool) $canonical->is_active || (bool) $duplicate->is_active,
            'effective_from' => $duplicate->effective_from ?? $canonical->effective_from,
            'effective_until' => $duplicate->effective_until ?? $canonical->effective_until,
            'source_reference' => filled($duplicate->source_reference ?? null)
                ? $duplicate->source_reference
                : $canonical->source_reference,
            'source_data' => $this->jsonValue(array_replace_recursive(
                $this->jsonArray($canonical->source_data ?? null),
                $this->jsonArray($duplicate->source_data ?? null),
            )),
            'updated_at' => now(),
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

    /**
     * @return array<string, mixed>
     */
    private function jsonArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function jsonValue(array $value): ?string
    {
        return $value === [] ? null : json_encode($value, JSON_THROW_ON_ERROR);
    }
};
