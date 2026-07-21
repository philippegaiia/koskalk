<?php

namespace App\Services;

use Illuminate\Support\Arr;

class SoapCuredOutputBuilder
{
    private const RESIDUAL_WATER_PERCENTAGE = 11.0;

    /**
     * @param  array<string, mixed>  $labeling
     * @return array{basis_weight: float, residual_water_percentage: float, rows: array<int, array<string, mixed>>, inci: string}
     */
    public function build(array $labeling, float $curedWeight): array
    {
        $variantKey = (string) Arr::get($labeling, 'default_variant_key', '');
        $variant = collect(Arr::get($labeling, 'list_variants', []))
            ->first(fn (mixed $candidate): bool => is_array($candidate) && ($candidate['key'] ?? null) === $variantKey);
        $variant = is_array($variant) ? $variant : [];
        $ingredientRows = collect(Arr::get($variant, 'ingredient_rows', []))
            ->filter(fn (mixed $row): bool => is_array($row));
        $nonWaterSourceWeight = (float) $ingredientRows
            ->reject(fn (array $row): bool => ($row['label'] ?? '') === 'AQUA')
            ->sum(fn (array $row): float => (float) ($row['weight'] ?? 0));
        $nonWaterOutputWeight = $curedWeight * 0.89;

        $rows = $ingredientRows
            ->map(function (array $row) use ($curedWeight, $nonWaterSourceWeight, $nonWaterOutputWeight): array {
                $isWater = ($row['label'] ?? '') === 'AQUA';
                $weight = $isWater
                    ? $curedWeight * 0.11
                    : ($nonWaterSourceWeight > 0
                        ? $nonWaterOutputWeight * ((float) ($row['weight'] ?? 0) / $nonWaterSourceWeight)
                        : 0.0);

                return [
                    'name' => (string) ($row['label'] ?? ''),
                    'role' => $this->role((string) ($row['kind'] ?? ''), $isWater),
                    'percentage' => $curedWeight > 0 ? round(($weight / $curedWeight) * 100, 4) : 0.0,
                    'weight' => round($weight, 4),
                    'sources' => array_values(Arr::wrap($row['source_ingredients'] ?? [])),
                ];
            })
            ->filter(fn (array $row): bool => $row['weight'] > 0)
            ->sortByDesc('weight')
            ->values()
            ->all();

        return [
            'basis_weight' => round($curedWeight, 4),
            'residual_water_percentage' => self::RESIDUAL_WATER_PERCENTAGE,
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
            'mixed_saponified_superfat' => 'soap_and_superfat',
            'theoretical_superfat' => 'superfat',
            'saponified_oil' => 'saponified_oil',
            'parfum' => 'aromatic_blend',
            'derived' => 'reaction_by_product',
            default => 'ingredient',
        };
    }
}
