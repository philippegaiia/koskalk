<?php

namespace App\Services;

use InvalidArgumentException;

class RecipeNormalizationService
{
    /**
     * @param  array<int, array{
     *     key?: string,
     *     name?: string,
     *     items?: array<int, array{
     *         ingredient_id?: int,
     *         percentage?: float|int|string|null,
     *         weight?: float|int|string|null,
     *         note?: ?string
     *     }>
     * }>  $phases
     * @return array{
     *     editing_mode: string,
     *     oil_weight: float,
     *     phases: array<int, array{
     *         key: string,
     *         name: string,
     *         items: array<int, array{
     *             ingredient_id: int|null,
     *             percentage: float,
     *             weight: float,
     *             note: string|null
     *         }>,
     *         totals: array{
     *             percentage_of_oils: float,
     *             weight: float
     *         }
     *     }>,
     *     totals: array{
     *         oil_percentage: float,
     *         formula_percentage_of_oils: float,
     *         formula_weight: float
     *     }
     * }
     */
    public function normalizeSoapRecipe(
        array $phases,
        float|int|string $oilWeight,
        string $editingMode = 'percent',
    ): array {
        $normalizedOilWeight = $this->normalizeNumericValue($oilWeight, 'oil weight');

        if ($normalizedOilWeight <= 0) {
            throw new InvalidArgumentException('The oil weight must be greater than zero.');
        }

        if (! in_array($editingMode, ['percent', 'weight'], true)) {
            throw new InvalidArgumentException('The editing mode must be either percent or weight.');
        }

        $normalizedPhases = [];
        $formulaPercentageOfOils = 0.0;
        $formulaWeight = 0.0;
        $oilPercentage = 0.0;

        foreach ($phases as $phaseIndex => $phase) {
            $phaseKey = (string) ($phase['key'] ?? 'phase_'.$phaseIndex);
            $phaseName = (string) ($phase['name'] ?? ucfirst(str_replace('_', ' ', $phaseKey)));
            $phaseItems = $phase['items'] ?? [];

            $normalizedItems = [];
            $phasePercentage = 0.0;
            $phaseWeight = 0.0;

            foreach ($phaseItems as $item) {
                $percentage = $editingMode === 'percent'
                    ? $this->normalizeNumericValue($item['percentage'] ?? 0, 'item percentage')
                    : $this->percentageFromWeight(
                        $this->normalizeNumericValue($item['weight'] ?? 0, 'item weight'),
                        $normalizedOilWeight
                    );

                $weight = $editingMode === 'weight'
                    ? $this->normalizeNumericValue($item['weight'] ?? 0, 'item weight')
                    : $this->weightFromPercentage($percentage, $normalizedOilWeight);

                $normalizedItems[] = [
                    'ingredient_id' => isset($item['ingredient_id']) ? (int) $item['ingredient_id'] : null,
                    'percentage' => $this->roundValue($percentage),
                    'weight' => $this->roundValue($weight),
                    'note' => $item['note'] ?? null,
                ];

                $phasePercentage += $percentage;
                $phaseWeight += $weight;
            }

            if ($phaseKey === 'saponified_oils') {
                $oilPercentage = $phasePercentage;
            }

            $formulaPercentageOfOils += $phasePercentage;
            $formulaWeight += $phaseWeight;

            $normalizedPhases[] = [
                'key' => $phaseKey,
                'name' => $phaseName,
                'items' => $normalizedItems,
                'totals' => [
                    'percentage_of_oils' => $this->roundValue($phasePercentage),
                    'weight' => $this->roundValue($phaseWeight),
                ],
            ];
        }

        return [
            'editing_mode' => $editingMode,
            'oil_weight' => $this->roundValue($normalizedOilWeight),
            'phases' => $normalizedPhases,
            'totals' => [
                'oil_percentage' => $this->roundValue($oilPercentage),
                'formula_percentage_of_oils' => $this->roundValue($formulaPercentageOfOils),
                'formula_weight' => $this->roundValue($formulaWeight),
            ],
        ];
    }

    public function weightFromPercentage(float $percentage, float $oilWeight): float
    {
        return $oilWeight * ($percentage / 100);
    }

    public function percentageFromWeight(float $weight, float $oilWeight): float
    {
        if ($oilWeight <= 0) {
            throw new InvalidArgumentException('The oil weight must be greater than zero.');
        }

        return ($weight / $oilWeight) * 100;
    }

    private function normalizeNumericValue(float|int|string|null $value, string $label): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        if (! is_numeric($value)) {
            throw new InvalidArgumentException("The {$label} must be numeric.");
        }

        return (float) $value;
    }

    private function roundValue(float $value): float
    {
        return round($value, 4);
    }
}
