<?php

namespace App\Services;

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
    public function normalize(array $payload): array
    {
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
