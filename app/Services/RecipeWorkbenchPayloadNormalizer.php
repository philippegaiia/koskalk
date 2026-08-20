<?php

namespace App\Services;

use App\Enums\MassUnit;
use App\Enums\NominalContentUnit;
use App\Enums\ProductionOutputType;
use App\Models\Ingredient;
use App\Models\ProductFamily;
use App\Models\ProductType;
use App\Models\Recipe;
use App\Models\RegulatoryRegime;
use App\Support\NumberLocale;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class RecipeWorkbenchPayloadNormalizer
{
    public function __construct(
        private readonly RecipeNormalizationService $recipeNormalizationService,
        private readonly RecipeWorkbenchPhaseBlueprints $recipeWorkbenchPhaseBlueprints,
        private readonly MassConverter $massConverter,
        private readonly SoapCalculationService $soapCalculationService,
        private readonly LyeLiquidAllocationService $lyeLiquidAllocationService,
        private readonly LyeLiquidIngredientValidator $lyeLiquidIngredientValidator,
        private readonly ProductClassificationService $productClassificationService,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     name: string,
     *     oil_weight: float,
     *     oil_unit: string,
     *     mass_grams: string,
     *     manufacturing_mode: string,
     *     exposure_mode: string,
     *     regulatory_regime: string,
     *     editing_mode: string,
     *     ifra_product_category_id: int|null,
     *     manufacturing_instructions: string|null,
     *     water_settings: array{mode: string, value: float},
     *     calculation_context: array<string, mixed>,
     *     phases: array<int, array<string, mixed>>,
     *     packaging_items: array<int, array<string, mixed>>,
     *     production_output_type: string,
     *     output_ingredient_id: int|null,
     *     ready_delay_days: int|null,
     *     product_reference: string|null,
     *     nominal_content_value: float|null,
     *     nominal_content_unit: string|null
     * }
     */
    public function normalize(
        array $payload,
        ?ProductFamily $productFamily = null,
        bool $requireComplete = true,
        ?Recipe $product = null,
    ): array {
        $productType = $productFamily instanceof ProductFamily
            ? $this->productClassificationService->resolveForSave(
                $productFamily,
                isset($payload['product_type_id']) && is_numeric($payload['product_type_id'])
                    ? (int) $payload['product_type_id']
                    : null,
                $product,
            )
            : null;

        if ($this->recipeWorkbenchPhaseBlueprints->isCosmeticFamily($productFamily)) {
            return $this->normalizeCosmetic($payload, $productType, $requireComplete);
        }

        $editingMode = ($payload['editing_mode'] ?? 'percentage') === 'weight' ? 'weight' : 'percent';
        $phasePayload = $this->phasePayload($payload);
        $massUnit = $this->normalizeMassUnit($payload['oil_unit'] ?? 'g');

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

        $lyeLiquidItems = $this->normalizeLyeLiquidItems($payload, $normalizedRecipe);

        $normalizedRecipe['phases'] = collect($normalizedRecipe['phases'])
            ->map(function (array $phase) use ($lyeLiquidItems): array {
                if ($phase['key'] !== 'lye_water') {
                    return $phase;
                }

                return [
                    ...$phase,
                    'items' => $lyeLiquidItems,
                    'totals' => [
                        'percentage_of_oils' => 0.0,
                        'weight' => (float) $this->roundMass(
                            collect($lyeLiquidItems)->reduce(
                                fn (string $total, array $item): string => bcadd(
                                    $total,
                                    NumberLocale::normalizeDecimalString($item['weight'] ?? null) ?? '0',
                                    18,
                                ),
                                '0',
                            ),
                        ),
                    ],
                ];
            })
            ->all();

        $name = trim((string) ($payload['name'] ?? 'Untitled Soap Formula'));

        return [
            'name' => $name !== '' ? $name : 'Untitled Soap Formula',
            'product_type_id' => $productType?->id,
            'oil_weight' => $normalizedRecipe['oil_weight'],
            'oil_unit' => $massUnit->value,
            'mass_grams' => $this->massConverter->toGrams($normalizedRecipe['oil_weight'], $massUnit),
            'manufacturing_mode' => $this->normalizeManufacturingMode($payload['manufacturing_mode'] ?? 'saponify_in_formula'),
            'exposure_mode' => $this->normalizeExposureMode($payload['exposure_mode'] ?? 'rinse_off'),
            'regulatory_regime' => RegulatoryRegime::normalizeCode($payload['regulatory_regime'] ?? 'eu'),
            'editing_mode' => $editingMode === 'weight' ? 'weight' : 'percentage',
            'ifra_product_category_id' => isset($payload['ifra_product_category_id']) && is_numeric($payload['ifra_product_category_id'])
                ? (int) $payload['ifra_product_category_id']
                : null,
            'manufacturing_instructions' => $this->nullableTrimmedText($payload['manufacturing_instructions'] ?? null),
            'final_ingredient_list' => $this->nullableTrimmedText($payload['final_ingredient_list'] ?? null),
            'final_ingredient_list_basis_hash' => $this->nullableTrimmedText($payload['final_ingredient_list_basis_hash'] ?? null),
            'final_plain_ingredient_list' => $this->nullableTrimmedText($payload['final_plain_ingredient_list'] ?? null),
            'final_plain_ingredient_list_basis_hash' => $this->nullableTrimmedText($payload['final_plain_ingredient_list_basis_hash'] ?? null),
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
                'dual_lye_koh_percentage' => $this->boundedPercentage($payload['dual_lye_koh_percentage'] ?? 40),
                'superfat' => (float) ($payload['superfat'] ?? 5),
                'oil_weight' => $normalizedRecipe['oil_weight'],
                'oil_unit' => $massUnit->value,
                'mass_grams' => $this->massConverter->toGrams($normalizedRecipe['oil_weight'], $massUnit),
                'totals' => $normalizedRecipe['totals'],
            ],
            'phases' => array_map(function (array $phase): array {
                $phaseBlueprint = $this->recipeWorkbenchPhaseBlueprints->find($phase['key']);

                return [
                    'key' => $phase['key'],
                    'name' => $phase['name'],
                    'phase_type' => $phaseBlueprint['phase_type'] ?? null,
                    'is_system' => (bool) ($phaseBlueprint['is_system'] ?? false),
                    'items' => collect($phase['items'])
                        ->filter(fn (array $item): bool => $phase['key'] === 'lye_water'
                            || $item['ingredient_id'] !== null)
                        ->values()
                        ->all(),
                ];
            }, $normalizedRecipe['phases']),
            'packaging_items' => $this->normalizePackagingItems($payload['packaging_items'] ?? []),
            ...$this->normalizeOutputConfiguration($payload),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     name: string,
     *     product_type_id: int|null,
     *     oil_weight: float,
     *     oil_unit: string,
     *     mass_grams: string,
     *     manufacturing_mode: string,
     *     exposure_mode: string,
     *     regulatory_regime: string,
     *     editing_mode: string,
     *     ifra_product_category_id: int|null,
     *     manufacturing_instructions: string|null,
     *     water_settings: array<string, mixed>,
     *     calculation_context: array<string, mixed>,
     *     phases: array<int, array<string, mixed>>,
     *     packaging_items: array<int, array<string, mixed>>,
     *     production_output_type: string,
     *     output_ingredient_id: int|null,
     *     ready_delay_days: int|null,
     *     product_reference: string|null,
     *     nominal_content_value: float|null,
     *     nominal_content_unit: string|null
     * }
     */
    private function normalizeCosmetic(array $payload, ?ProductType $productType, bool $requireComplete): array
    {
        $editingMode = ($payload['editing_mode'] ?? 'percentage') === 'weight' ? 'weight' : 'percent';
        $totalBatchWeight = $this->positiveWeight($payload['oil_weight'] ?? 0, 'total batch weight');
        $massUnit = $this->normalizeMassUnit($payload['oil_unit'] ?? 'g');
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

                if ($percentage < 0 || $weight < 0) {
                    throw ValidationException::withMessages([
                        'formula_total' => 'Formula percentages and weights must not be negative.',
                    ]);
                }

                $hasIngredient = $item['ingredient_id'] !== null;
                $hasValue = $percentage > 0 || $weight > 0;

                if (! $hasIngredient) {
                    if ($hasValue && $requireComplete) {
                        throw ValidationException::withMessages([
                            'formula_total' => 'Choose an ingredient for every cosmetic row with a percentage or weight.',
                        ]);
                    }

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
            'product_type_id' => $productType?->id,
            'oil_weight' => round($totalBatchWeight, 4),
            'oil_unit' => $massUnit->value,
            'mass_grams' => $this->massConverter->toGrams($totalBatchWeight, $massUnit),
            'manufacturing_mode' => 'blend_only',
            'exposure_mode' => $this->normalizeExposureMode($payload['exposure_mode'] ?? 'leave_on'),
            'regulatory_regime' => RegulatoryRegime::normalizeCode($payload['regulatory_regime'] ?? 'eu'),
            'editing_mode' => $editingMode === 'weight' ? 'weight' : 'percentage',
            'ifra_product_category_id' => isset($payload['ifra_product_category_id']) && is_numeric($payload['ifra_product_category_id'])
                ? (int) $payload['ifra_product_category_id']
                : null,
            'manufacturing_instructions' => $this->nullableTrimmedText($payload['manufacturing_instructions'] ?? null),
            'final_ingredient_list' => $this->nullableTrimmedText($payload['final_ingredient_list'] ?? null),
            'final_ingredient_list_basis_hash' => $this->nullableTrimmedText($payload['final_ingredient_list_basis_hash'] ?? null),
            'final_plain_ingredient_list' => $this->nullableTrimmedText($payload['final_plain_ingredient_list'] ?? null),
            'final_plain_ingredient_list_basis_hash' => $this->nullableTrimmedText($payload['final_plain_ingredient_list_basis_hash'] ?? null),
            'water_settings' => [],
            'calculation_context' => [
                'editing_mode' => $editingMode === 'weight' ? 'weight' : 'percentage',
                'oil_weight' => round($totalBatchWeight, 4),
                'oil_unit' => $massUnit->value,
                'mass_grams' => $this->massConverter->toGrams($totalBatchWeight, $massUnit),
                'formula_total_percentage' => $formulaPercentage,
                'formula_weight' => $formulaWeight,
                'calculation_basis' => 'total_formula',
            ],
            'phases' => $normalizedPhases,
            'packaging_items' => $this->normalizePackagingItems($payload['packaging_items'] ?? []),
            ...$this->normalizeOutputConfiguration($payload),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{production_output_type: string, output_ingredient_id: int|null, ready_delay_days: int|null, product_reference: string|null, nominal_content_value: float|null, nominal_content_unit: string|null}
     */
    private function normalizeOutputConfiguration(array $payload): array
    {
        $outputType = ProductionOutputType::tryFrom((string) ($payload['production_output_type'] ?? ProductionOutputType::FinishedProduct->value));

        if (! $outputType instanceof ProductionOutputType) {
            throw ValidationException::withMessages([
                'production_output_type' => __('production_bench.production.validation.output_type_invalid'),
            ]);
        }

        $rawOutputIngredientId = $payload['output_ingredient_id'] ?? null;
        $outputIngredientId = $rawOutputIngredientId;
        $outputIngredientId = $outputIngredientId === null || $outputIngredientId === ''
            ? null
            : (is_numeric($outputIngredientId) && (int) $outputIngredientId > 0 ? (int) $outputIngredientId : null);

        if ($rawOutputIngredientId !== null
            && $rawOutputIngredientId !== ''
            && $outputIngredientId === null) {
            throw ValidationException::withMessages([
                'output_ingredient_id' => __('production_bench.production.validation.output_ingredient_invalid'),
            ]);
        }

        $readyDelayDays = $payload['ready_delay_days'] ?? null;

        if ($readyDelayDays === '') {
            $readyDelayDays = null;
        }

        if ($readyDelayDays !== null
            && (! is_numeric($readyDelayDays) || (int) $readyDelayDays < 0 || (float) $readyDelayDays !== (float) (int) $readyDelayDays)) {
            throw ValidationException::withMessages([
                'ready_delay_days' => __('production_bench.settings.ready_delay_whole_number'),
            ]);
        }

        $productReference = $this->nullableTrimmedText($payload['product_reference'] ?? null);

        if ($productReference !== null && Str::length($productReference) > 100) {
            throw ValidationException::withMessages([
                'product_reference' => __('validation.max.string', ['attribute' => 'product reference', 'max' => 100]),
            ]);
        }

        $rawNominalContentValue = $payload['nominal_content_value'] ?? null;
        $rawNominalContentUnit = $payload['nominal_content_unit'] ?? null;
        $nominalContentValue = $rawNominalContentValue === null || $rawNominalContentValue === ''
            ? null
            : (is_numeric($rawNominalContentValue) ? (float) $rawNominalContentValue : null);
        $nominalContentUnit = is_string($rawNominalContentUnit)
            ? NominalContentUnit::tryFrom($rawNominalContentUnit)
            : null;

        if ($nominalContentValue !== null && $nominalContentValue <= 0) {
            throw ValidationException::withMessages([
                'nominal_content_value' => __('validation.gt.numeric', ['attribute' => 'nominal content', 'value' => 0]),
            ]);
        }

        if ($nominalContentValue === null && $rawNominalContentValue !== null && $rawNominalContentValue !== '') {
            throw ValidationException::withMessages([
                'nominal_content_value' => __('validation.numeric', ['attribute' => 'nominal content']),
            ]);
        }

        if (($nominalContentValue === null) !== ($nominalContentUnit === null)) {
            throw ValidationException::withMessages([
                $nominalContentValue === null ? 'nominal_content_value' : 'nominal_content_unit' => __('workbench.settings.nominal_content_pair_required'),
            ]);
        }

        return [
            'production_output_type' => $outputType->value,
            'output_ingredient_id' => $outputIngredientId,
            'ready_delay_days' => $readyDelayDays === null ? null : (int) $readyDelayDays,
            'product_reference' => $outputType === ProductionOutputType::FinishedProduct && $productReference !== null
                ? Str::upper($productReference)
                : null,
            'nominal_content_value' => $outputType === ProductionOutputType::FinishedProduct ? $nominalContentValue : null,
            'nominal_content_unit' => $outputType === ProductionOutputType::FinishedProduct ? $nominalContentUnit?->value : null,
        ];
    }

    /**
     * @return array<int, array{
     *     packaging_item_id: int|null,
     *     name: string,
     *     components_per_unit: float,
     *     notes: string|null,
     *     position: int
     * }>
     */
    private function normalizePackagingItems(mixed $items): array
    {
        return collect(is_array($items) ? $items : [])
            ->filter(fn (mixed $row): bool => is_array($row))
            ->map(function (array $row): array {
                return [
                    'packaging_item_id' => isset($row['packaging_item_id']) && is_numeric($row['packaging_item_id'])
                        ? (int) $row['packaging_item_id']
                        : null,
                    'name' => trim((string) ($row['name'] ?? '')),
                    'components_per_unit' => round(max(0, (float) ($row['components_per_unit'] ?? $row['quantity'] ?? 1)), 3),
                    'notes' => filled($row['notes'] ?? null) ? (string) $row['notes'] : null,
                ];
            })
            ->filter(fn (array $row): bool => $row['name'] !== '' && $row['components_per_unit'] > 0)
            ->values()
            ->map(fn (array $row, int $index): array => [
                ...$row,
                'position' => $index + 1,
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, array<string, mixed>>
     */
    private function phasePayload(array $payload): array
    {
        $phaseItems = $payload['phase_items'] ?? [];

        return array_map(function (array $phase) use ($phaseItems): array {
            $rows = $phase['key'] === 'lye_water'
                ? []
                : ($phaseItems[$phase['key']] ?? []);

            return [
                'key' => $phase['key'],
                'name' => $phase['name'],
                'items' => array_map(function (array $row): array {
                    return [
                        'ingredient_id' => $this->ingredientId($row['ingredient_id'] ?? null),
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
     * @param  array<string, mixed>  $normalizedRecipe
     * @return array<int, array{ingredient_id: int|null, percentage: float, weight: float, note: mixed}>
     */
    private function normalizeLyeLiquidItems(array $payload, array $normalizedRecipe): array
    {
        $rows = collect($this->lyeLiquidIngredientValidator->normalizeRows(
            collect($payload['phase_items']['lye_water'] ?? [])->values()->all(),
        ));

        if ($rows->isEmpty()) {
            return [];
        }

        $oilPhase = collect($normalizedRecipe['phases'] ?? [])->firstWhere('key', 'saponified_oils');
        $oilItems = collect(is_array($oilPhase) ? ($oilPhase['items'] ?? []) : []);
        $ingredients = Ingredient::query()
            ->with(['sapProfile', 'fattyAcidEntries.fattyAcid'])
            ->whereKey($oilItems->pluck('ingredient_id')->filter()->all())
            ->get()
            ->keyBy('id');
        $oils = $oilItems
            ->map(function (array $item) use ($ingredients): ?array {
                $ingredient = $ingredients->get($item['ingredient_id']);

                if (! $ingredient instanceof Ingredient || (float) ($item['weight'] ?? 0) <= 0) {
                    return null;
                }

                return [
                    'name' => $ingredient->display_name,
                    'weight' => (float) $item['weight'],
                    'koh_sap_value' => $ingredient->sapProfile?->koh_sap_value ?? 0,
                    'fatty_acid_profile' => $ingredient->normalizedFattyAcidProfile(),
                ];
            })
            ->filter()
            ->values()
            ->all();

        $calculation = $this->soapCalculationService->calculate($oils, [
            'superfat' => (float) ($payload['superfat'] ?? 5),
            'lye_type' => $payload['lye_type'] ?? 'naoh',
            'dual_lye_koh_percentage' => (float) ($payload['dual_lye_koh_percentage'] ?? 40),
            'koh_purity_percentage' => (float) ($payload['koh_purity_percentage'] ?? 90),
            'water_mode' => $payload['water_mode'] ?? 'percent_of_oils',
            'water_value' => (float) ($payload['water_value'] ?? 38),
        ]);

        try {
            $allocation = $this->lyeLiquidAllocationService->allocateFresh(
                (string) $calculation['lye']['water']['weight'],
                $rows->all(),
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'phase_items.lye_water' => $exception->getMessage(),
            ]);
        }

        return collect($allocation['substitutions'])
            ->map(fn (array $row): array => [
                'ingredient_id' => $this->ingredientId($row['ingredient_id'] ?? null),
                'percentage' => (float) $row['percentage'],
                'weight' => (float) $row['weight'],
                'note' => $row['note'] ?? null,
            ])
            ->all();
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
                            'ingredient_id' => $this->ingredientId($row['ingredient_id'] ?? null),
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

    private function ingredientId(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $ingredientId = (int) $value;

        return $ingredientId > 0 ? $ingredientId : null;
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

    private function boundedPercentage(mixed $value): float
    {
        return max(0.0, min(100.0, (float) $value));
    }

    private function roundMass(string $value): string
    {
        return bcadd(bcadd($value, '0.00005', 5), '0', 4);
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

    private function normalizeMassUnit(mixed $value): MassUnit
    {
        try {
            return MassUnit::fromInput($value);
        } catch (InvalidArgumentException) {
            return MassUnit::Gram;
        }
    }

    private function nullableTrimmedText(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }
}
