<?php

namespace App\Services;

use App\Models\ProductFamily;
use App\Models\ProductType;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class RecipeWorkbenchPayloadNormalizer
{
    public function __construct(
        private readonly RecipeNormalizationService $recipeNormalizationService,
        private readonly RecipeWorkbenchPhaseBlueprints $recipeWorkbenchPhaseBlueprints,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     name: string,
     *     oil_weight: float,
     *     oil_unit: string,
     *     manufacturing_mode: string,
     *     exposure_mode: string,
     *     regulatory_regime: string,
     *     editing_mode: string,
     *     ifra_product_category_id: int|null,
     *     water_settings: array{mode: string, value: float},
     *     calculation_context: array<string, mixed>,
     *     phases: array<int, array<string, mixed>>
     * }
     */
    public function normalize(array $payload, ?ProductFamily $productFamily = null, bool $requireComplete = true): array
    {
        if ($this->recipeWorkbenchPhaseBlueprints->isCosmeticFamily($productFamily)) {
            return $this->normalizeCosmetic($payload, $productFamily, $requireComplete);
        }

        $editingMode = ($payload['editing_mode'] ?? 'percentage') === 'weight' ? 'weight' : 'percent';
        $phasePayload = $this->phasePayload($payload);

        try {
            $normalizedRecipe = $this->recipeNormalizationService->normalizeSoapRecipe(
                $phasePayload,
                (float) ($payload['oil_weight'] ?? 0),
                $editingMode,
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                $this->normalizationErrorField($exception->getMessage()) => $exception->getMessage(),
            ]);
        }

        if (abs($normalizedRecipe['totals']['oil_percentage'] - 100) > 0.01) {
            throw ValidationException::withMessages([
                'saponified_oils' => 'Saponified oils must total 100% before the formula can be saved.',
            ]);
        }

        $name = trim((string) ($payload['name'] ?? 'Untitled Soap Formula'));

        return [
            'name' => $name !== '' ? $name : 'Untitled Soap Formula',
            'product_type_id' => null,
            'oil_weight' => $normalizedRecipe['oil_weight'],
            'oil_unit' => in_array($payload['oil_unit'] ?? 'g', ['g', 'oz', 'lb'], true) ? $payload['oil_unit'] : 'g',
            'manufacturing_mode' => $this->normalizeManufacturingMode($payload['manufacturing_mode'] ?? 'saponify_in_formula'),
            'exposure_mode' => $this->normalizeExposureMode($payload['exposure_mode'] ?? 'rinse_off'),
            'regulatory_regime' => $this->normalizeRegulatoryRegime($payload['regulatory_regime'] ?? 'eu'),
            'editing_mode' => $editingMode === 'weight' ? 'weight' : 'percentage',
            'ifra_product_category_id' => isset($payload['ifra_product_category_id']) && is_numeric($payload['ifra_product_category_id'])
                ? (int) $payload['ifra_product_category_id']
                : null,
            'water_settings' => [
                'mode' => in_array($payload['water_mode'] ?? 'percent_of_oils', ['percent_of_oils', 'lye_ratio', 'lye_concentration'], true)
                    ? $payload['water_mode']
                    : 'percent_of_oils',
                'value' => (float) ($payload['water_value'] ?? 38),
            ],
            'calculation_context' => [
                'editing_mode' => $editingMode === 'weight' ? 'weight' : 'percentage',
                'lye_type' => in_array($payload['lye_type'] ?? 'naoh', ['naoh', 'koh', 'dual'], true)
                    ? $payload['lye_type']
                    : 'naoh',
                'koh_purity_percentage' => (float) ($payload['koh_purity_percentage'] ?? 90),
                'dual_lye_koh_percentage' => (float) ($payload['dual_lye_koh_percentage'] ?? 40),
                'superfat' => (float) ($payload['superfat'] ?? 5),
                'oil_weight' => $normalizedRecipe['oil_weight'],
                'oil_unit' => in_array($payload['oil_unit'] ?? 'g', ['g', 'oz', 'lb'], true) ? $payload['oil_unit'] : 'g',
                'totals' => $normalizedRecipe['totals'],
            ],
            'phases' => array_map(function (array $phase): array {
                $phaseBlueprint = $this->recipeWorkbenchPhaseBlueprints->find($phase['key']);

                return [
                    'key' => $phase['key'],
                    'name' => $phase['name'],
                    'phase_type' => $phaseBlueprint['phase_type'] ?? null,
                    'is_system' => (bool) ($phaseBlueprint['is_system'] ?? false),
                    'items' => array_values(array_filter($phase['items'], function (array $item): bool {
                        return $item['ingredient_id'] !== null
                            && ($item['percentage'] > 0 || $item['weight'] > 0);
                    })),
                ];
            }, $normalizedRecipe['phases']),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     name: string,
     *     product_type_id: int|null,
     *     oil_weight: float,
     *     oil_unit: string,
     *     manufacturing_mode: string,
     *     exposure_mode: string,
     *     regulatory_regime: string,
     *     editing_mode: string,
     *     ifra_product_category_id: int|null,
     *     water_settings: array<string, mixed>,
     *     calculation_context: array<string, mixed>,
     *     phases: array<int, array<string, mixed>>
     * }
     */
    private function normalizeCosmetic(array $payload, ?ProductFamily $productFamily, bool $requireComplete): array
    {
        $editingMode = ($payload['editing_mode'] ?? 'percentage') === 'weight' ? 'weight' : 'percent';
        $totalBatchWeight = $this->positiveWeight($payload['oil_weight'] ?? 0, 'total batch weight');
        $phases = $this->cosmeticPhasePayload($payload);

        $normalizedPhases = [];
        $formulaPercentage = 0.0;
        $formulaWeight = 0.0;

        foreach ($phases as $phase) {
            $items = [];
            $phasePercentage = 0.0;
            $phaseWeight = 0.0;

            foreach ($phase['items'] as $item) {
                $percentage = $editingMode === 'percent'
                    ? $this->numericValue($item['percentage'] ?? 0)
                    : $this->percentageFromWeight($this->numericValue($item['weight'] ?? 0), $totalBatchWeight);

                $weight = $editingMode === 'weight'
                    ? $this->numericValue($item['weight'] ?? 0)
                    : $this->weightFromPercentage($percentage, $totalBatchWeight);

                $hasValue = $percentage > 0 || $weight > 0;

                if ($item['ingredient_id'] === null) {
                    if ($hasValue && $requireComplete) {
                        throw ValidationException::withMessages([
                            'formula_total' => 'Choose an ingredient for every cosmetic row with a percentage or weight.',
                        ]);
                    }

                    continue;
                }

                if (! $hasValue) {
                    continue;
                }

                $items[] = [
                    'ingredient_id' => $item['ingredient_id'],
                    'percentage' => round($percentage, 4),
                    'weight' => round($weight, 4),
                    'note' => $item['note'] ?? null,
                ];

                $phasePercentage += $percentage;
                $phaseWeight += $weight;
            }

            $formulaPercentage += $phasePercentage;
            $formulaWeight += $phaseWeight;

            $normalizedPhases[] = [
                'key' => $phase['key'],
                'name' => $phase['name'],
                'phase_type' => 'cosmetic_phase',
                'is_system' => false,
                'items' => $items,
            ];
        }

        $formulaPercentage = round($formulaPercentage, 4);
        $formulaWeight = round($formulaWeight, 4);

        if ($requireComplete && abs($formulaPercentage - 100) > 0.01) {
            throw ValidationException::withMessages([
                'formula_total' => 'Cosmetic formula must total 100% before it can be saved.',
            ]);
        }

        $name = trim((string) ($payload['name'] ?? 'Untitled Cosmetic Formula'));

        return [
            'name' => $name !== '' ? $name : 'Untitled Cosmetic Formula',
            'product_type_id' => $this->productTypeId($payload, $productFamily),
            'oil_weight' => round($totalBatchWeight, 4),
            'oil_unit' => in_array($payload['oil_unit'] ?? 'g', ['g', 'oz', 'lb'], true) ? $payload['oil_unit'] : 'g',
            'manufacturing_mode' => 'blend_only',
            'exposure_mode' => $this->normalizeExposureMode($payload['exposure_mode'] ?? 'leave_on'),
            'regulatory_regime' => $this->normalizeRegulatoryRegime($payload['regulatory_regime'] ?? 'eu'),
            'editing_mode' => $editingMode === 'weight' ? 'weight' : 'percentage',
            'ifra_product_category_id' => isset($payload['ifra_product_category_id']) && is_numeric($payload['ifra_product_category_id'])
                ? (int) $payload['ifra_product_category_id']
                : null,
            'water_settings' => [],
            'calculation_context' => [
                'editing_mode' => $editingMode === 'weight' ? 'weight' : 'percentage',
                'oil_weight' => round($totalBatchWeight, 4),
                'oil_unit' => in_array($payload['oil_unit'] ?? 'g', ['g', 'oz', 'lb'], true) ? $payload['oil_unit'] : 'g',
                'formula_total_percentage' => $formulaPercentage,
                'formula_weight' => $formulaWeight,
                'calculation_basis' => 'total_formula',
            ],
            'phases' => $normalizedPhases,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, array<string, mixed>>
     */
    private function phasePayload(array $payload): array
    {
        $phaseItems = $payload['phase_items'] ?? [];

        return array_map(function (array $phase) use ($phaseItems): array {
            $rows = $phaseItems[$phase['key']] ?? [];

            return [
                'key' => $phase['key'],
                'name' => $phase['name'],
                'items' => array_map(function (array $row): array {
                    return [
                        'ingredient_id' => isset($row['ingredient_id']) ? (int) $row['ingredient_id'] : null,
                        'percentage' => (float) ($row['percentage'] ?? 0),
                        'weight' => (float) ($row['weight'] ?? 0),
                        'note' => $row['note'] ?? null,
                    ];
                }, is_array($rows) ? $rows : []),
            ];
        }, $this->recipeWorkbenchPhaseBlueprints->all());
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, array{key: string, name: string, items: array<int, array<string, mixed>>}>
     */
    private function cosmeticPhasePayload(array $payload): array
    {
        $phaseItems = is_array($payload['phase_items'] ?? null) ? $payload['phase_items'] : [];
        $phases = collect(is_array($payload['phases'] ?? null) ? $payload['phases'] : [])
            ->filter(fn (mixed $phase): bool => is_array($phase))
            ->values();

        if ($phases->isEmpty()) {
            $phases = collect(array_keys($phaseItems))
                ->filter(fn (mixed $phaseKey): bool => is_string($phaseKey))
                ->values()
                ->map(fn (string $phaseKey): array => [
                    'key' => $phaseKey,
                    'name' => str($phaseKey)->replace('_', ' ')->title()->toString(),
                ]);
        }

        if ($phases->isEmpty()) {
            $phases = collect([['key' => 'phase_a', 'name' => 'Phase A']]);
        }

        $knownPhaseKeys = $phases
            ->map(fn (array $phase, int $index): string => $this->cosmeticPhaseKey($phase['key'] ?? null, $index))
            ->all();

        collect(array_keys($phaseItems))
            ->filter(fn (mixed $phaseKey): bool => is_string($phaseKey) && ! in_array($phaseKey, $knownPhaseKeys, true))
            ->each(function (string $phaseKey) use (&$phases): void {
                $phases->push([
                    'key' => $phaseKey,
                    'name' => str($phaseKey)->replace('_', ' ')->title()->toString(),
                ]);
            });

        return $phases
            ->map(function (array $phase, int $index) use ($phaseItems): array {
                $phaseKey = $this->cosmeticPhaseKey($phase['key'] ?? null, $index);
                $rows = is_array($phaseItems[$phaseKey] ?? null) ? $phaseItems[$phaseKey] : [];

                return [
                    'key' => $phaseKey,
                    'name' => trim((string) ($phase['name'] ?? '')) !== ''
                        ? trim((string) $phase['name'])
                        : 'Phase '.chr(65 + $index),
                    'items' => array_map(function (array $row): array {
                        return [
                            'ingredient_id' => isset($row['ingredient_id']) ? (int) $row['ingredient_id'] : null,
                            'percentage' => $row['percentage'] ?? 0,
                            'weight' => $row['weight'] ?? 0,
                            'note' => $row['note'] ?? null,
                        ];
                    }, array_values(array_filter($rows, fn (mixed $row): bool => is_array($row)))),
                ];
            })
            ->values()
            ->all();
    }

    private function cosmeticPhaseKey(mixed $value, int $index): string
    {
        $key = str((string) $value)->slug('_')->toString();

        return $key !== '' ? $key : 'phase_'.chr(97 + $index);
    }

    private function productTypeId(array $payload, ?ProductFamily $productFamily): ?int
    {
        if (! isset($payload['product_type_id']) || ! is_numeric($payload['product_type_id'])) {
            return null;
        }

        $productType = ProductType::query()->find((int) $payload['product_type_id']);

        if (! $productType instanceof ProductType) {
            return null;
        }

        if ($productFamily instanceof ProductFamily && $productType->product_family_id !== $productFamily->id) {
            return null;
        }

        return $productType->id;
    }

    private function positiveWeight(mixed $value, string $label): float
    {
        $weight = $this->numericValue($value);

        if ($weight <= 0) {
            throw ValidationException::withMessages([
                'oil_weight' => "The {$label} must be greater than zero.",
            ]);
        }

        return $weight;
    }

    private function numericValue(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        if (! is_numeric($value)) {
            throw ValidationException::withMessages([
                'formula_total' => 'Cosmetic formula values must be numeric.',
            ]);
        }

        return (float) $value;
    }

    private function weightFromPercentage(float $percentage, float $totalBatchWeight): float
    {
        return $totalBatchWeight * ($percentage / 100);
    }

    private function percentageFromWeight(float $weight, float $totalBatchWeight): float
    {
        if ($totalBatchWeight <= 0) {
            return 0.0;
        }

        return ($weight / $totalBatchWeight) * 100;
    }

    private function normalizationErrorField(string $message): string
    {
        $normalizedMessage = str($message)->lower();

        if ($normalizedMessage->contains('oil weight')) {
            return 'oil_weight';
        }

        if ($normalizedMessage->contains('editing mode')) {
            return 'editing_mode';
        }

        if ($normalizedMessage->contains('percentage')) {
            return 'percentage';
        }

        if ($normalizedMessage->contains('weight')) {
            return 'weight';
        }

        return 'draft';
    }

    private function normalizeManufacturingMode(?string $value): string
    {
        return in_array($value, ['saponify_in_formula', 'blend_only'], true)
            ? $value
            : 'saponify_in_formula';
    }

    private function normalizeExposureMode(?string $value): string
    {
        return in_array($value, ['rinse_off', 'leave_on'], true)
            ? $value
            : 'rinse_off';
    }

    private function normalizeRegulatoryRegime(?string $value): string
    {
        return in_array($value, ['eu'], true)
            ? $value
            : 'eu';
    }
}
