<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\IngredientSubstanceEntry;
use App\Models\RegulatoryRegime;
use App\Models\RegulatoryRegimeSubstanceRule;

class SubstanceComplianceService
{
    public function __construct(
        private readonly IngredientFormulaContextResolver $ingredientFormulaContextResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $soapCalculation
     * @return array<string, mixed>
     */
    public function evaluate(array $payload, ?array $soapCalculation = null): array
    {
        $ruleState = $this->ruleState($payload);

        if ($ruleState['rules_by_substance_id'] === []) {
            $basis = $this->basisState($payload, $this->ingredientFormulaContextResolver->raw($payload), $soapCalculation);

            return [
                'basis' => $basis,
                'regime' => [
                    'code' => $ruleState['regime_code'],
                    'label' => $ruleState['regime_label'],
                    'uses_regime_rules' => false,
                ],
                'summary' => $this->summary([]),
                'rows' => [],
                'warnings' => $this->warnings([], [
                    ...$ruleState,
                    'uses_regime_rules' => false,
                ]),
            ];
        }

        $contexts = $this->ingredientFormulaContextResolver->resolve($payload, [
            'substanceEntries.substance',
        ]);
        $basis = $this->basisState($payload, $contexts, $soapCalculation);
        $rows = $this->restrictionRows($contexts, $basis['formula_weight'], $ruleState);

        return [
            'basis' => $basis,
            'regime' => [
                'code' => $ruleState['regime_code'],
                'label' => $ruleState['regime_label'],
                'uses_regime_rules' => $ruleState['uses_regime_rules'],
            ],
            'summary' => $this->summary($rows),
            'rows' => $rows,
            'warnings' => $this->warnings($rows, $ruleState),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{status: string, fail_count: int, warning_count: int, pass_count: int, row_count: int}
     */
    private function summary(array $rows): array
    {
        $failStatuses = ['prohibited', 'over_limit'];
        $warningStatuses = ['unknown_concentration', 'watch', 'no_limit_recorded'];
        $failCount = count(array_filter($rows, fn (array $row): bool => in_array($row['status'], $failStatuses, true)));
        $warningCount = count(array_filter($rows, fn (array $row): bool => in_array($row['status'], $warningStatuses, true)));

        return [
            'status' => match (true) {
                $failCount > 0 => 'fail',
                $warningCount > 0 => 'warning',
                default => 'pass',
            },
            'fail_count' => $failCount,
            'warning_count' => $warningCount,
            'pass_count' => count($rows) - $failCount - $warningCount,
            'row_count' => count($rows),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $ruleState
     * @return array<int, string>
     */
    private function warnings(array $rows, array $ruleState): array
    {
        $warnings = [];

        if (! ($ruleState['uses_regime_rules'] ?? false)) {
            $warnings[] = 'No active substance rule set is available for the selected regime.';
        }

        foreach ($rows as $row) {
            if ($row['status'] === 'unknown_concentration') {
                $warnings[] = "{$row['substance_name']} has a selected regime rule, but at least one ingredient has unknown concentration data.";
            }
        }

        return array_values(array_unique($warnings));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     uses_regime_rules: bool,
     *     regime_code: string,
     *     regime_label: string,
     *     exposure_mode: string,
     *     rules_by_substance_id: array<int, array<string, mixed>>
     * }
     */
    private function ruleState(array $payload): array
    {
        $regimeCode = RegulatoryRegime::normalizeCode($payload['regulatory_regime'] ?? 'eu');
        $exposureMode = (string) ($payload['exposure_mode'] ?? 'rinse_off');
        $today = now()->toDateString();

        $regime = RegulatoryRegime::query()
            ->where('code', $regimeCode)
            ->whereIn('status', ['active', 'preview'])
            ->where(function ($query) use ($today): void {
                $query->whereNull('effective_from')
                    ->orWhereDate('effective_from', '<=', $today);
            })
            ->where(function ($query) use ($today): void {
                $query->whereNull('effective_until')
                    ->orWhereDate('effective_until', '>=', $today);
            })
            ->with(['substanceRules' => function ($query) use ($today, $exposureMode): void {
                $query
                    ->where('is_active', true)
                    ->whereIn('exposure_scope', ['both', $exposureMode])
                    ->where(function ($query) use ($today): void {
                        $query->whereNull('effective_from')
                            ->orWhereDate('effective_from', '<=', $today);
                    })
                    ->where(function ($query) use ($today): void {
                        $query->whereNull('effective_until')
                            ->orWhereDate('effective_until', '>=', $today);
                    })
                    ->with('substance');
            }])
            ->first();

        if (! $regime instanceof RegulatoryRegime) {
            return [
                'uses_regime_rules' => false,
                'regime_code' => $regimeCode,
                'regime_label' => strtoupper($regimeCode),
                'exposure_mode' => $exposureMode,
                'rules_by_substance_id' => [],
            ];
        }

        $rulesBySubstanceId = [];

        foreach ($regime->substanceRules as $rule) {
            if (! $rule instanceof RegulatoryRegimeSubstanceRule || $rule->substance === null) {
                continue;
            }

            $rulesBySubstanceId[(int) $rule->substance_id] = [
                'substance_id' => (int) $rule->substance_id,
                'substance_name' => $rule->substance->name,
                'entity_type' => $rule->substance->entity_type,
                'inci_name' => $rule->substance->inci_name,
                'cas_number' => $rule->substance->cas_number,
                'allergen_id' => $rule->substance->allergen_id,
                'rule_type' => $rule->rule_type,
                'max_percent' => $this->ruleMaxPercent($rule, $exposureMode),
                'threshold_operator' => filled($rule->threshold_operator)
                    ? (string) $rule->threshold_operator
                    : 'less_than_or_equal',
                'label_warning_text' => $rule->label_warning_text,
                'source_reference' => $rule->source_reference,
            ];
        }

        return [
            'uses_regime_rules' => true,
            'regime_code' => $regime->code,
            'regime_label' => $regime->name,
            'exposure_mode' => $exposureMode,
            'rules_by_substance_id' => $rulesBySubstanceId,
        ];
    }

    /**
     * @param  array<int, array{
     *     phase_key: string,
     *     weight: float,
     *     ingredient: Ingredient|null,
     *     ingredient_name: string,
     *     is_user_owned: bool
     * }>  $contexts
     * @param  array<string, mixed>  $ruleState
     * @return array<int, array<string, mixed>>
     */
    private function restrictionRows(array $contexts, float $formulaWeight, array $ruleState): array
    {
        if ($formulaWeight <= 0) {
            return [];
        }

        $rowsBySubstance = [];

        foreach ($contexts as $context) {
            $ingredient = $context['ingredient'];

            if (! $ingredient instanceof Ingredient) {
                continue;
            }

            foreach ($ingredient->substanceEntries as $entry) {
                if (! $entry instanceof IngredientSubstanceEntry) {
                    continue;
                }

                $rule = $ruleState['rules_by_substance_id'][$entry->substance_id] ?? null;

                if (! is_array($rule)) {
                    continue;
                }

                $key = (int) $rule['substance_id'];

                if (! array_key_exists($key, $rowsBySubstance)) {
                    $rowsBySubstance[$key] = $this->baseRow($rule);
                }

                $isUnknown = $entry->concentration_percent === null || $entry->concentration_source === 'unknown';

                if ($isUnknown) {
                    $rowsBySubstance[$key]['has_unknown_concentration'] = true;
                } else {
                    $rowsBySubstance[$key]['percent_of_formula'] +=
                        (($context['weight'] / $formulaWeight) * 100)
                        * (((float) $entry->concentration_percent) / 100);
                }

                $rowsBySubstance[$key]['source_ingredients'][] = $context['ingredient_name'];
                $rowsBySubstance[$key]['source_is_user_owned'][] = $context['is_user_owned'];
                $rowsBySubstance[$key]['concentration_sources'][] = $entry->concentration_source;
            }
        }

        $rows = array_map(fn (array $row): array => $this->finalizeRow($row), array_values($rowsBySubstance));

        usort($rows, function (array $left, array $right): int {
            $statusOrder = [
                'prohibited' => 0,
                'over_limit' => 1,
                'unknown_concentration' => 2,
                'watch' => 3,
                'no_limit_recorded' => 4,
                'within_limit' => 5,
            ];

            return ($statusOrder[$left['status']] ?? 99) <=> ($statusOrder[$right['status']] ?? 99)
                ?: strcmp($left['substance_name'], $right['substance_name']);
        });

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $rule
     * @return array<string, mixed>
     */
    private function baseRow(array $rule): array
    {
        return [
            'substance_id' => $rule['substance_id'],
            'substance_name' => $rule['substance_name'],
            'entity_type' => $rule['entity_type'],
            'inci_name' => $rule['inci_name'],
            'cas_number' => $rule['cas_number'],
            'allergen_id' => $rule['allergen_id'],
            'rule_type' => $rule['rule_type'],
            'max_percent' => $rule['max_percent'],
            'threshold_operator' => $rule['threshold_operator'],
            'label_warning_text' => $rule['label_warning_text'],
            'source_reference' => $rule['source_reference'],
            'percent_of_formula' => 0.0,
            'has_unknown_concentration' => false,
            'source_ingredients' => [],
            'source_is_user_owned' => [],
            'concentration_sources' => [],
        ];
    }

    private function finalizeRow(array $row): array
    {
        $row['percent_of_formula'] = round((float) $row['percent_of_formula'], 5);
        $row['source_ingredients'] = array_values(array_unique(array_filter($row['source_ingredients'])));
        $row['source_is_user_owned'] = array_values($row['source_is_user_owned']);
        $row['concentration_sources'] = array_values(array_unique(array_filter($row['concentration_sources'])));
        $row['status'] = $this->rowStatus($row);
        $row['requires_review'] = in_array($row['status'], ['unknown_concentration', 'watch', 'no_limit_recorded'], true);
        $row['status_label'] = $this->statusLabel($row['status']);

        return $row;
    }

    private function rowStatus(array $row): string
    {
        if ((bool) $row['has_unknown_concentration']) {
            return 'unknown_concentration';
        }

        if ($row['rule_type'] === 'prohibited' && (float) $row['percent_of_formula'] > 0) {
            return 'prohibited';
        }

        if ($row['rule_type'] === 'watch') {
            return 'watch';
        }

        if ($row['rule_type'] === 'restricted') {
            if ($row['max_percent'] === null) {
                return 'no_limit_recorded';
            }

            return $this->passesLimit(
                (float) $row['percent_of_formula'],
                (float) $row['max_percent'],
                (string) $row['threshold_operator'],
            )
                ? 'within_limit'
                : 'over_limit';
        }

        return 'watch';
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'prohibited' => 'Prohibited',
            'over_limit' => 'Over limit',
            'within_limit' => 'Within limit',
            'unknown_concentration' => 'Needs concentration data',
            'no_limit_recorded' => 'Limit missing',
            default => 'Watch',
        };
    }

    private function passesLimit(float $percentOfFormula, float $maxPercent, string $thresholdOperator): bool
    {
        return match ($thresholdOperator) {
            'less_than' => $percentOfFormula < $maxPercent,
            default => $percentOfFormula <= $maxPercent,
        };
    }

    private function ruleMaxPercent(RegulatoryRegimeSubstanceRule $rule, string $exposureMode): ?float
    {
        $value = $exposureMode === 'leave_on'
            ? $rule->leave_on_max_percent
            : $rule->rinse_off_max_percent;

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, array{
     *     phase_key: string,
     *     weight: float,
     *     ingredient: Ingredient|null,
     *     ingredient_name: string,
     *     is_user_owned: bool
     * }>  $contexts
     * @param  array<string, mixed>|null  $soapCalculation
     * @return array{label: string, note: string, formula_weight: float}
     */
    private function basisState(array $payload, array $contexts, ?array $soapCalculation): array
    {
        $phaseWeight = array_sum(array_map(fn (array $context): float => (float) $context['weight'], $contexts));

        if (($payload['manufacturing_mode'] ?? 'saponify_in_formula') !== 'saponify_in_formula') {
            $declaredFormulaWeight = (float) ($payload['oil_weight'] ?? 0);

            return [
                'label' => 'Current formula basis',
                'note' => 'Restrictions use the current finished blend basis.',
                'formula_weight' => round($declaredFormulaWeight > 0 ? $declaredFormulaWeight : $phaseWeight, 5),
            ];
        }

        if (! is_array($soapCalculation)) {
            return [
                'label' => 'Current formula basis',
                'note' => 'Soap calculation is incomplete, so restrictions currently use only live ingredient rows.',
                'formula_weight' => round($phaseWeight, 5),
            ];
        }

        $waterWeight = (float) data_get($soapCalculation, 'lye.water.weight', 0);
        $naohWeight = (float) data_get($soapCalculation, 'lye.selected.naoh_weight', 0);
        $kohToWeigh = (float) data_get($soapCalculation, 'lye.selected.koh_to_weigh', 0);

        return [
            'label' => 'Current batch basis',
            'note' => 'Restrictions use the live batch basis from oils, lye, water, and post-reaction additions.',
            'formula_weight' => round($phaseWeight + $waterWeight + $naohWeight + $kohToWeigh, 5),
        ];
    }
}
