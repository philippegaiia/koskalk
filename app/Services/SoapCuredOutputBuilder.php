<?php

namespace App\Services;

use App\Support\NumberLocale;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

class SoapCuredOutputBuilder
{
    public function __construct(
        private readonly LyeLiquidAllocationService $lyeLiquidAllocationService,
    ) {}

    /**
     * @param  array<string, mixed>  $labeling
     * @return array{basis_weight: float, residual_water_percentage: float, rows: array<int, array<string, mixed>>, inci: string}
     */
    public function build(array $labeling, string|int|float $curedWeight): array
    {
        $normalizedCuredWeight = $this->decimal($curedWeight);
        $variantKey = (string) Arr::get($labeling, 'default_variant_key', '');
        $variant = collect(Arr::get($labeling, 'list_variants', []))
            ->first(fn (mixed $candidate): bool => is_array($candidate) && ($candidate['key'] ?? null) === $variantKey);
        $variant = is_array($variant) ? $variant : [];
        $ingredientRows = collect(Arr::get($variant, 'ingredient_rows', []))
            ->filter(fn (mixed $row): bool => is_array($row));
        $freshLiquidWeight = $ingredientRows->reduce(
            fn (string $total, array $row): string => bcadd($total, $this->lyeLiquidWeight($row), 18),
            '0',
        );
        $residualWeights = $this->residualWeights($ingredientRows, $normalizedCuredWeight, $freshLiquidWeight);

        $rows = $ingredientRows
            ->values()
            ->map(function (array $row, int $index) use ($normalizedCuredWeight, $residualWeights): array {
                $isWater = ($row['label'] ?? '') === 'AQUA';
                $totalWeight = $this->decimal($row['weight'] ?? 0);
                $lyeLiquidWeight = $this->lyeLiquidWeight($row);
                $lyeLiquidWeight = bccomp($lyeLiquidWeight, $totalWeight, 18) > 0
                    ? $totalWeight
                    : $lyeLiquidWeight;
                $nonLyeWeight = bcsub($totalWeight, $lyeLiquidWeight, 18);
                $weight = bcadd($nonLyeWeight, $residualWeights[$index] ?? '0', 18);

                return [
                    'name' => (string) ($row['label'] ?? ''),
                    'role' => $this->role((string) ($row['kind'] ?? ''), $isWater),
                    'percentage' => bccomp($normalizedCuredWeight, '0', 18) > 0
                        ? (float) $this->roundMass(bcmul(bcdiv($weight, $normalizedCuredWeight, 18), '100', 18))
                        : 0.0,
                    'weight' => (float) $this->roundMass($weight),
                    'sources' => array_values(Arr::wrap($row['source_ingredients'] ?? [])),
                ];
            })
            ->filter(fn (array $row): bool => $row['weight'] > 0)
            ->sortByDesc('weight')
            ->values()
            ->all();

        return [
            'basis_weight' => (float) $this->roundMass($normalizedCuredWeight),
            'residual_water_percentage' => LyeLiquidAllocationService::CURED_RESIDUAL_PERCENTAGE,
            'rows' => $rows,
            'inci' => (string) ($variant['final_label_text'] ?? $labeling['print_ingredient_list_text'] ?? ''),
        ];
    }

    private function role(string $kind, bool $isWater): string
    {
        if ($isWater) {
            return 'residual_water';
        }

        return match ($kind) {
            'lye_liquid' => 'residual_lye_liquid',
            'mixed_saponified_superfat' => 'soap_and_superfat',
            'theoretical_superfat' => 'superfat',
            'saponified_oil' => 'saponified_oil',
            'parfum' => 'aromatic_blend',
            'derived' => 'reaction_by_product',
            default => 'ingredient',
        };
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $ingredientRows
     * @return array<int, string>
     */
    private function residualWeights(Collection $ingredientRows, string $curedWeight, string $freshLiquidWeight): array
    {
        if (bccomp($freshLiquidWeight, '0', 18) <= 0) {
            return [];
        }

        $substitutions = $ingredientRows
            ->values()
            ->map(fn (array $row, int $index): array => [
                'row_index' => $index,
                'percentage' => bcmul(
                    bcdiv($this->lyeLiquidWeight($row), $freshLiquidWeight, 18),
                    '100',
                    12,
                ),
            ])
            ->filter(fn (array $row): bool => bccomp($row['percentage'], '0', 12) > 0)
            ->all();
        $allocation = $this->lyeLiquidAllocationService->allocateCuredResidual($curedWeight, $substitutions);

        return collect($allocation['substitutions'])
            ->mapWithKeys(fn (array $row): array => [
                (int) $row['row_index'] => $this->decimal($row['weight'] ?? 0),
            ])
            ->all();
    }

    /** @param array<string, mixed> $row */
    private function lyeLiquidWeight(array $row): string
    {
        if (array_key_exists('lye_liquid_weight', $row)) {
            $weight = $this->decimal($row['lye_liquid_weight']);

            return bccomp($weight, '0', 18) > 0 ? $weight : '0';
        }

        if (($row['label'] ?? '') !== 'AQUA' && ($row['kind'] ?? '') !== 'lye_liquid') {
            return '0';
        }

        $weight = $this->decimal($row['weight'] ?? 0);

        return bccomp($weight, '0', 18) > 0 ? $weight : '0';
    }

    private function decimal(mixed $value): string
    {
        return NumberLocale::normalizeDecimalString($value) ?? '0';
    }

    private function roundMass(string $value): string
    {
        return bcadd(bcadd($value, '0.00005', 5), '0', 4);
    }
}
