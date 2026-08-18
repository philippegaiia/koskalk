<?php

namespace App\Services;

use App\Support\NumberLocale;
use InvalidArgumentException;

class LyeLiquidAllocationService
{
    public const CURED_RESIDUAL_PERCENTAGE = 11.0;

    /** @param array<int, array<string, mixed>> $substitutions */
    public function allocateFresh(string|int|float $totalLiquidWeight, array $substitutions): array
    {
        return $this->allocate($totalLiquidWeight, $substitutions);
    }

    /** @param array<int, array<string, mixed>> $substitutions */
    public function allocateCuredResidual(string|int|float $curedSoapWeight, array $substitutions): array
    {
        $normalizedCuredSoapWeight = $this->decimal($curedSoapWeight);

        if ($normalizedCuredSoapWeight === null || bccomp($normalizedCuredSoapWeight, '0', 18) < 0) {
            throw new InvalidArgumentException(__('workbench.validation.lye_liquid_negative_cured'));
        }

        return $this->allocate(
            bcmul($normalizedCuredSoapWeight, '0.11', 18),
            $substitutions,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $substitutions
     * @return array{
     *     total_weight: float,
     *     water_percentage: float,
     *     water_weight: float,
     *     substitution_percentage: float,
     *     substitutions: array<int, array<string, mixed>>
     * }
     */
    private function allocate(string|int|float $totalLiquidWeight, array $substitutions): array
    {
        $normalizedTotalLiquidWeight = $this->decimal($totalLiquidWeight);

        if ($normalizedTotalLiquidWeight === null || bccomp($normalizedTotalLiquidWeight, '0', 18) < 0) {
            throw new InvalidArgumentException(__('workbench.validation.lye_liquid_negative_total'));
        }

        $normalizedSubstitutions = [];
        $substitutionPercentages = [];
        $substitutionPercentage = '0';

        foreach ($substitutions as $substitution) {
            $percentage = $this->decimal($substitution['percentage'] ?? null);

            if (
                $percentage === null
                || bccomp($percentage, '0', 12) < 0
                || bccomp($percentage, '100', 12) > 0
            ) {
                throw new InvalidArgumentException(__('workbench.validation.lye_liquid_percentage_range'));
            }

            $substitutionPercentage = bcadd($substitutionPercentage, $percentage, 12);
            $substitutionPercentages[] = $percentage;
            $normalizedSubstitutions[] = [
                ...$substitution,
                'percentage' => round((float) $percentage, 4),
            ];
        }

        if (bccomp($substitutionPercentage, '100.00001', 12) > 0) {
            throw new InvalidArgumentException(__('workbench.validation.lye_liquid_percentage_total'));
        }

        $waterPercentage = bccomp($substitutionPercentage, '100', 12) >= 0
            ? '0'
            : bcsub('100', $substitutionPercentage, 12);
        $weights = $this->conservedWeights($normalizedTotalLiquidWeight, $waterPercentage, $substitutionPercentages);
        $normalizedSubstitutions = array_map(
            fn (array $substitution, int $index): array => [
                ...$substitution,
                'weight' => $weights['substitutions'][$index],
            ],
            $normalizedSubstitutions,
            array_keys($normalizedSubstitutions),
        );

        return [
            'total_weight' => (float) $this->roundMass($normalizedTotalLiquidWeight),
            'water_percentage' => round((float) $waterPercentage, 4),
            'water_weight' => $weights['water'],
            'substitution_percentage' => round((float) $substitutionPercentage, 4),
            'substitutions' => $normalizedSubstitutions,
        ];
    }

    /**
     * @param  array<int, string>  $substitutionPercentages
     * @return array{water: float, substitutions: array<int, float>}
     */
    private function conservedWeights(
        string $totalWeight,
        string $waterPercentage,
        array $substitutionPercentages,
    ): array {
        $roundedTotalWeight = $this->roundMass($totalWeight);
        $allocations = bccomp($waterPercentage, '0', 12) > 0
            ? [['type' => 'water', 'index' => null, 'percentage' => $waterPercentage]]
            : [];

        foreach ($substitutionPercentages as $index => $percentage) {
            if (bccomp($percentage, '0', 12) > 0) {
                $allocations[] = ['type' => 'substitution', 'index' => $index, 'percentage' => $percentage];
            }
        }

        $remainingWeight = $roundedTotalWeight;
        $waterWeight = '0.0000';
        $substitutionWeights = array_fill(0, count($substitutionPercentages), '0.0000');
        $finalAllocationIndex = array_key_last($allocations);

        foreach ($allocations as $allocationIndex => $allocation) {
            if ($allocationIndex === $finalAllocationIndex) {
                $weight = $remainingWeight;
            } else {
                $proportionalWeight = bcmul(
                    $roundedTotalWeight,
                    bcdiv($allocation['percentage'], '100', 12),
                    12,
                );
                $weight = bcadd($proportionalWeight, '0.00005', 4);
            }

            $weight = bccomp($weight, $remainingWeight, 4) > 0 ? $remainingWeight : $weight;
            $remainingWeight = bcsub($remainingWeight, $weight, 4);

            if ($allocation['type'] === 'water') {
                $waterWeight = $weight;
            } else {
                $substitutionWeights[$allocation['index']] = $weight;
            }
        }

        return [
            'water' => (float) $waterWeight,
            'substitutions' => array_map(floatval(...), $substitutionWeights),
        ];
    }

    private function decimal(mixed $value): ?string
    {
        if (is_float($value)) {
            if (! is_finite($value)) {
                return null;
            }

            return rtrim(rtrim(sprintf('%.18F', $value), '0'), '.');
        }

        return NumberLocale::normalizeDecimalString($value);
    }

    private function roundMass(string $value): string
    {
        return bcadd(bcadd($value, '0.00005', 5), '0', 4);
    }
}
