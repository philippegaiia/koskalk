<?php

namespace App\Services\Production;

use App\Enums\StockUnitKind;
use App\Models\ProductionRun;

class ProductionOutputReconciliation
{
    /**
     * @return array{unit: string, planned: string, actual: ?string, variance: ?string, variance_percentage: ?string}
     */
    public function forProduction(ProductionRun $production): array
    {
        $isIntermediate = $production->actual_output_mass_grams !== null
            || $production->outputLot?->unit_kind === StockUnitKind::Mass;
        $unit = $isIntermediate ? 'g' : 'unit';
        $planned = $isIntermediate
            ? (string) $production->basis_quantity_grams
            : (string) $production->expected_units;
        $actual = $isIntermediate
            ? $production->actual_output_mass_grams
            : ($production->actual_output_units === null ? null : (string) $production->actual_output_units);

        if ($actual === null) {
            return [
                'unit' => $unit,
                'planned' => $this->canonical($planned),
                'actual' => null,
                'variance' => null,
                'variance_percentage' => null,
            ];
        }

        $variance = bcsub($actual, $planned, 9);
        $variancePercentage = bccomp($planned, '0', 18) === 0
            ? null
            : $this->roundDecimal(
                bcdiv(bcmul($variance, '100', 18), $planned, 18),
                2,
            );

        return [
            'unit' => $unit,
            'planned' => $this->canonical($planned),
            'actual' => $this->canonical($actual),
            'variance' => $this->canonical($variance),
            'variance_percentage' => $variancePercentage,
        ];
    }

    private function roundDecimal(string $value, int $scale): string
    {
        $negative = bccomp($value, '0', 18) < 0;
        $absolute = $negative ? bcmul($value, '-1', 18) : $value;
        $increment = '0.'.str_repeat('0', $scale).'5';
        $rounded = bcadd($absolute, $increment, $scale + 1);
        $rounded = bcadd($rounded, '0', $scale);

        return $negative && bccomp($rounded, '0', $scale) !== 0 ? '-'.$rounded : $rounded;
    }

    private function canonical(string $value): string
    {
        $normalized = bcadd($value, '0', 9);
        $negative = str_starts_with($normalized, '-');
        $absolute = $negative ? substr($normalized, 1) : $normalized;
        $absolute = rtrim(rtrim($absolute, '0'), '.');

        if ($absolute === '' || $absolute === '0') {
            return '0';
        }

        return $negative ? '-'.$absolute : $absolute;
    }
}
