<?php

namespace App\Services;

use App\Enums\IngredientCategory;
use App\Enums\IngredientSubcategory;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\RecipePhase;
use App\Models\RecipeVersion;
use App\Models\RecipeVersionCosting;
use App\Models\RecipeVersionCostingItem;
use App\Models\RecipeVersionCostingPackagingItem;
use App\Models\RecipeVersionPackagingItem;
use App\Models\User;
use App\Support\NumberLocale;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class RecipeVersionCostPreviewBuilder
{
    public function __construct(
        private readonly RecipeVersionCostingSynchronizer $costingSynchronizer,
        private readonly MassConverter $massConverter,
        private readonly RecipeWorkbenchService $recipeWorkbenchService,
        private readonly CanonicalSoapAlkaliResolver $alkaliResolver,
        private readonly LyeLiquidAllocationService $lyeLiquidAllocationService,
        private readonly LiquidSoapFormulaProcessor $liquidSoapFormulaProcessor,
    ) {}

    /**
     * @return array{
     *     currency: string,
     *     ingredient_rows: array<int, array<string, mixed>>,
     *     packaging_rows: array<int, array<string, mixed>>,
     *     ingredient_total: float,
     *     packaging_total: float,
     *     total_cost: float,
     *     cost_per_unit: float|null,
     *     has_unpriced_rows: bool
     * }
     */
    public function build(Recipe $recipe, RecipeVersion $version, User $user, string|int|float $batchBasisValue, ?int $unitsProduced): array
    {
        abort_unless((int) $version->recipe_id === (int) $recipe->id, 404);
        Gate::forUser($user)->authorize('view', $recipe);

        $existingCosting = RecipeVersionCosting::query()
            ->with(['items', 'packagingItems.packagingItem'])
            ->where('recipe_version_id', $version->id)
            ->first();

        $costing = $this->costingSynchronizer->projectCosting($version, $existingCosting);
        $currency = $costing->currency ?: $user->defaultCurrency();
        $version = $costing->recipeVersion;
        $snapshot = ($recipe->productFamily?->usesLiquidSoapPasteBench() ?? false)
            ? $this->recipeWorkbenchService->versionSnapshot($recipe, (int) $version->id)
            : null;

        $preview = $this->buildFromTransientRows(
            formula: $this->transientFormulaForVersion(
                $version,
                $recipe->productFamily?->calculation_basis === 'total_formula' ? 'cosmetic' : 'soap',
                [],
                $snapshot,
            ),
            costing: $this->transientCostingForProjection(
                $costing,
                $batchBasisValue,
                $version->batch_unit ?: 'g',
                $unitsProduced === null ? null : (string) $unitsProduced,
                $currency,
            ),
        );

        return $this->legacyPreviewShape($preview);
    }

    /**
     * @return array{
     *     currency: string,
     *     ingredient_rows: array<int, array<string, mixed>>,
     *     packaging_rows: array<int, array<string, mixed>>,
     *     ingredient_total: float,
     *     packaging_total: float,
     *     total_cost: float,
     *     cost_per_unit: float|null,
     *     has_unpriced_rows: bool
     * }
     */
    public function buildFromCosting(
        Recipe $recipe,
        RecipeVersion $version,
        RecipeVersionCosting $costing,
        string|int|float $batchBasisValue,
        ?int $unitsProduced,
        bool $includeDerivedRows = false,
        bool $requireCompletePackagingTotal = false,
        ?string $batchBasisUnit = null,
    ): array {
        $version = RecipeVersion::withoutGlobalScopes()
            ->with([
                'phases' => fn ($query) => $query->withoutGlobalScopes()->orderBy('sort_order'),
                'phases.items' => fn ($query) => $query->withoutGlobalScopes()->with('ingredient')->orderBy('position'),
                'packagingItems' => fn ($query) => $query->withoutGlobalScopes()->with('packagingItem')->orderBy('position'),
            ])
            ->findOrFail($version->id);

        $costing = $costing->loadMissing(['items.ingredient', 'packagingItems.packagingItem']);
        $snapshot = $this->recipeWorkbenchService->versionSnapshot($recipe, (int) $version->id);

        $syntheticRows = $includeDerivedRows
            ? $this->savedSyntheticRows($version, $costing, $snapshot['calculation'] ?? null, $snapshot['draft'] ?? [])
            : [];

        if ($includeDerivedRows) {
            $syntheticRows = [
                ...$syntheticRows,
                ...$this->savedCostingOnlyRows($version, $costing, $syntheticRows),
            ];
        }

        $transientCosting = $this->transientCostingForProjection(
            $costing,
            $batchBasisValue,
            $batchBasisUnit ?: $version->batch_unit ?: 'g',
            $unitsProduced === null ? null : (string) $unitsProduced,
            $costing->currency ?: $recipe->ownerUser()?->defaultCurrency() ?? 'EUR',
        );
        $transientCosting['packaging_items'] = $this->savedPackagingRows($version, $costing);
        $preview = $this->buildFromTransientRows(
            formula: $this->transientFormulaForVersion(
                $version,
                $recipe->productFamily?->calculation_basis === 'total_formula' ? 'cosmetic' : 'soap',
                $syntheticRows,
                $snapshot,
            ),
            costing: $transientCosting,
        );

        return $requireCompletePackagingTotal ? $preview : $this->legacyPreviewShape($preview);
    }

    /**
     * Read-only costing projection for a current draft or saved version.
     * Prices are matched only by formula identity; an explicit null price stays
     * unpriced, while a missing row never falls back to a catalog default.
     *
     * @param  array<string, mixed>  $formula
     * @param  array<string, mixed>  $costing
     * @return array<string, mixed>
     */
    public function buildFromTransientRows(array $formula, array $costing): array
    {
        $formulaBasis = $this->decimal($formula['basis_weight'] ?? null) ?? '0';
        $costingBasis = $this->decimal($costing['oil_weight_for_costing'] ?? null) ?? '0';
        $formulaUnit = (string) ($formula['basis_unit'] ?? 'g');
        $costingUnit = (string) ($costing['oil_unit_for_costing'] ?? $formulaUnit);
        $family = (string) ($formula['family'] ?? 'soap');
        $unitsProduced = $this->nullablePositiveDecimal($costing['units_produced'] ?? null);
        $currency = (string) ($costing['currency'] ?? 'EUR');

        $pricesByIdentity = [];
        foreach (($costing['items'] ?? []) as $priceRow) {
            if (! is_array($priceRow)) {
                continue;
            }

            $pricesByIdentity[$this->lotKey(
                (int) ($priceRow['ingredient_id'] ?? 0),
                (string) ($priceRow['phase_key'] ?? ''),
                (int) ($priceRow['position'] ?? 0),
            )] = $priceRow['price_per_kg'] ?? null;
        }

        $ingredientRows = [];
        $formulaPhases = is_array($formula['phases'] ?? null) ? $formula['phases'] : [];
        foreach ($formulaPhases as $phaseKey => $phase) {
            if (! is_string($phaseKey) || ! is_array($phase)) {
                continue;
            }

            foreach (($phase['rows'] ?? []) as $row) {
                if (is_array($row)) {
                    $ingredientRows[] = $this->transientIngredientRow(
                        $row,
                        $phaseKey,
                        (string) ($phase['label'] ?? $phaseKey),
                        $family,
                        $formulaBasis,
                        $costingBasis,
                        $formulaUnit,
                        $costingUnit,
                        $pricesByIdentity,
                    );
                }
            }
        }

        foreach (($formula['synthetic_rows'] ?? []) as $row) {
            if (is_array($row)) {
                $phaseKey = (string) ($row['phase_key'] ?? 'derived');
                $ingredientRows[] = $this->transientIngredientRow(
                    $row,
                    $phaseKey,
                    (string) ($row['phase_name'] ?? $phaseKey),
                    $family,
                    $formulaBasis,
                    $costingBasis,
                    $formulaUnit,
                    $costingUnit,
                    $pricesByIdentity,
                );
            }
        }

        $packagingRows = $this->transientPackagingRows(
            is_array($formula['packaging_items'] ?? null) ? $formula['packaging_items'] : [],
            is_array($costing['packaging_items'] ?? null) ? $costing['packaging_items'] : [],
            $unitsProduced,
        );

        $ingredientTotal = $this->sumKnownCosts($ingredientRows);
        $packagingTotal = $packagingRows === []
            ? '0'
            : ($unitsProduced === null ? null : $this->sumKnownCosts($packagingRows));
        $totalCost = $packagingTotal === null ? null : $this->addMoney($ingredientTotal, $packagingTotal);
        $costPerUnit = $totalCost !== null && $unitsProduced !== null
            ? $this->normalizeMoneyDecimal(bcdiv($totalCost, $unitsProduced, 18))
            : null;
        $publicIngredientRows = array_map(fn (array $row): array => $this->publicIngredientRow($row), $ingredientRows);
        $publicPackagingRows = array_map(fn (array $row): array => $this->publicPackagingRow($row), $packagingRows);

        return [
            'currency' => $currency,
            'ingredient_rows' => $publicIngredientRows,
            'packaging_rows' => $publicPackagingRows,
            'ingredient_total' => $this->decimalFloat($ingredientTotal),
            'packaging_total' => $packagingTotal === null ? null : $this->decimalFloat($packagingTotal),
            'total_cost' => $totalCost === null ? null : $this->decimalFloat($totalCost),
            'cost_per_unit' => $costPerUnit === null ? null : $this->decimalFloat($costPerUnit),
            'has_unpriced_rows' => collect($ingredientRows)->merge($packagingRows)
                ->contains(fn (array $row): bool => (bool) $row['is_unpriced']),
            'has_incomplete_packaging_total' => $packagingRows !== [] && $unitsProduced === null,
            'units_produced' => $unitsProduced === null ? null : $this->decimalFloat($unitsProduced),
            'basis_weight' => $this->decimalFloat($costingBasis),
            'basis_unit' => $costingUnit,
            'percentage_basis' => $family === 'soap' ? 'oils' : 'formula',
        ];
    }

    /** @param array<string, mixed> $row
     * @param  array<string, string|null>  $pricesByIdentity
     * @return array<string, mixed>
     */
    private function transientIngredientRow(
        array $row,
        string $phaseKey,
        string $phaseName,
        string $family,
        string $formulaBasis,
        string $costingBasis,
        string $formulaUnit,
        string $costingUnit,
        array $pricesByIdentity,
    ): array {
        $ingredientId = (int) ($row['ingredient_id'] ?? 0);
        $position = (int) ($row['position'] ?? 0);
        $lotKey = $this->lotKey($ingredientId, $phaseKey, $position);
        $percentage = $this->decimal($row['percentage'] ?? null) ?? '0';
        $formulaWeight = $this->decimal($row['weight'] ?? null) ?? '0';
        $formulaBasisGrams = $this->massConverter->toGrams($formulaBasis, $formulaUnit);
        $costingBasisGrams = $this->massConverter->toGrams($costingBasis, $costingUnit);
        $isDerived = (bool) ($row['is_derived'] ?? false);
        $usesFormulaWeight = ($this->costsStoredWeight($phaseKey) || $isDerived) && bccomp($formulaBasisGrams, '0', 18) > 0;
        $quantityGrams = $usesFormulaWeight
            ? bcmul(
                $this->massConverter->toGrams($formulaWeight, $formulaUnit),
                bcdiv($costingBasisGrams, $formulaBasisGrams, 18),
                18,
            )
            : bcdiv(bcmul($costingBasisGrams, $percentage, 18), '100', 18);
        $quantity = $this->massConverter->fromGrams($quantityGrams, $costingUnit);
        $percentageBasis = $family === 'soap' ? 'oils' : 'formula';
        $displayPercentage = ($this->costsStoredWeight($phaseKey) || $isDerived) && bccomp($costingBasisGrams, '0', 18) > 0
            ? bcmul(bcdiv($quantityGrams, $costingBasisGrams, 18), '100', 18)
            : $percentage;
        $hasPrice = array_key_exists($lotKey, $pricesByIdentity);
        $price = $hasPrice ? $this->decimal($pricesByIdentity[$lotKey]) : null;
        $quantityInKg = $this->massConverter->convert($quantity, $costingUnit, 'kg');
        $lineCost = $price === null ? null : $this->normalizeMoneyDecimal(bcmul($quantityInKg, $price, 18));

        return [
            'lot_key' => $lotKey,
            'ingredient_id' => $ingredientId,
            'phase_key' => $phaseKey,
            'phase_name' => $phaseName,
            'position' => $position,
            'ingredient_name' => (string) ($row['name'] ?? 'Ingredient'),
            'percentage_decimal' => $displayPercentage,
            'percentage_basis' => $percentageBasis,
            'percentage_label' => $percentageBasis === 'oils' ? '%' : '% formula',
            'quantity_decimal' => $quantity,
            'unit' => $costingUnit,
            'price_per_kg_decimal' => $price,
            'line_cost_decimal' => $lineCost,
            'is_unpriced' => $price === null,
        ];
    }

    /** @param list<array<string, mixed>> $formulaRows
     * @param  list<array<string, mixed>>  $costingRows
     * @return list<array<string, mixed>>
     */
    private function transientPackagingRows(array $formulaRows, array $costingRows, ?string $unitsProduced): array
    {
        $rowsByIdentity = [];
        foreach ($costingRows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $rowsByIdentity[$this->packagingIdentity($row)][] = $row;
        }

        $occurrences = [];
        $rows = [];
        foreach ($formulaRows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $identity = $this->packagingIdentity($row);
            $index = $occurrences[$identity] ?? 0;
            $occurrences[$identity] = $index + 1;
            $costingRow = $rowsByIdentity[$identity][$index] ?? null;
            $rows[] = $this->transientPackagingRow($row, is_array($costingRow) ? $costingRow : null, $unitsProduced);
        }

        $matchedOccurrences = [];
        foreach ($costingRows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $identity = $this->packagingIdentity($row);
            $index = $matchedOccurrences[$identity] ?? 0;
            $matchedOccurrences[$identity] = $index + 1;

            if ($index >= ($occurrences[$identity] ?? 0)) {
                $row['position'] = (int) ($row['position'] ?? count($rows) + 1);
                $rows[] = $this->transientPackagingRow($row, $row, $unitsProduced);
            }
        }

        return $rows;
    }

    /** @param array<string, mixed> $formulaRow
     * @param  array<string, mixed>|null  $costingRow
     * @return array<string, mixed>
     */
    private function transientPackagingRow(array $formulaRow, ?array $costingRow, ?string $unitsProduced): array
    {
        $unitCostValue = is_array($costingRow) && array_key_exists('unit_cost', $costingRow)
            ? $costingRow['unit_cost']
            : null;
        $unitCost = $this->decimal($unitCostValue);
        $componentsValue = is_array($costingRow) && array_key_exists('components_per_unit', $costingRow)
            ? $costingRow['components_per_unit']
            : ($formulaRow['components_per_unit'] ?? '0');
        $components = $this->decimal($componentsValue) ?? '0';
        $costPerUnit = $unitCost === null ? null : $this->normalizeMoneyDecimal(bcmul($unitCost, $components, 18));
        $lineCost = $costPerUnit === null || $unitsProduced === null
            ? null
            : $this->normalizeMoneyDecimal(bcmul($costPerUnit, $unitsProduced, 18));

        return [
            'packaging_item_id' => isset($formulaRow['packaging_item_id']) ? (int) $formulaRow['packaging_item_id'] : null,
            'position' => (int) ($formulaRow['position'] ?? 0),
            'name' => (string) ($formulaRow['name'] ?? 'Packaging'),
            'material_code' => $formulaRow['material_code'] ?? null,
            'components_per_unit_decimal' => $components,
            'unit_cost_decimal' => $unitCost,
            'cost_per_finished_unit_decimal' => $costPerUnit,
            'line_cost_decimal' => $lineCost,
            'is_unpriced' => $unitCost === null,
        ];
    }

    /** @param array<string, mixed> $row */
    private function packagingIdentity(array $row): string
    {
        return implode(':', [
            (string) ($row['packaging_item_id'] ?? 'unlinked'),
            mb_strtolower((string) ($row['name'] ?? '')),
        ]);
    }

    /** @param list<array<string, mixed>> $rows */
    private function sumKnownCosts(array $rows): string
    {
        $total = '0';
        foreach ($rows as $row) {
            if ($row['line_cost_decimal'] !== null) {
                $total = bcadd($total, $row['line_cost_decimal'], 18);
            }
        }

        return $this->normalizeMoneyDecimal($total);
    }

    private function addMoney(string $left, string $right): string
    {
        return $this->normalizeMoneyDecimal(bcadd($left, $right, 18));
    }

    private function normalizeMoneyDecimal(string $value): string
    {
        return bcadd($value, '0', 18);
    }

    private function nullablePositiveDecimal(mixed $value): ?string
    {
        $normalized = $this->decimal($value);

        return $normalized !== null && bccomp($normalized, '0', 18) > 0 ? $normalized : null;
    }

    private function decimal(mixed $value): ?string
    {
        if (is_float($value)) {
            if (! is_finite($value)) {
                return null;
            }

            $value = rtrim(rtrim(sprintf('%.18F', $value), '0'), '.') ?: '0';
        }

        return NumberLocale::normalizeDecimalString($value);
    }

    private function decimalFloat(string $value): float
    {
        return (float) $value;
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function publicIngredientRow(array $row): array
    {
        $row['percentage'] = $this->decimalFloat($row['percentage_decimal']);
        $row['quantity'] = $this->decimalFloat($row['quantity_decimal']);
        $row['price_per_kg'] = $row['price_per_kg_decimal'] === null
            ? null
            : $this->decimalFloat($row['price_per_kg_decimal']);
        $row['line_cost'] = $row['line_cost_decimal'] === null
            ? null
            : $this->decimalFloat($row['line_cost_decimal']);
        unset($row['percentage_decimal'], $row['quantity_decimal'], $row['price_per_kg_decimal'], $row['line_cost_decimal']);

        return $row;
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function publicPackagingRow(array $row): array
    {
        $row['components_per_unit'] = $this->decimalFloat($row['components_per_unit_decimal']);
        $row['unit_cost'] = $row['unit_cost_decimal'] === null
            ? null
            : $this->decimalFloat($row['unit_cost_decimal']);
        $row['cost_per_finished_unit'] = $row['cost_per_finished_unit_decimal'] === null
            ? null
            : $this->decimalFloat($row['cost_per_finished_unit_decimal']);
        $row['line_cost'] = $row['line_cost_decimal'] === null
            ? null
            : $this->decimalFloat($row['line_cost_decimal']);
        unset($row['components_per_unit_decimal'], $row['unit_cost_decimal'], $row['cost_per_finished_unit_decimal'], $row['line_cost_decimal']);

        return $row;
    }

    /**
     * @param  array<string, mixed>|null  $calculation
     * @param  array<string, mixed>  $draft
     * @return list<array<string, mixed>>
     */
    private function savedSyntheticRows(
        RecipeVersion $version,
        RecipeVersionCosting $costing,
        ?array $calculation,
        array $draft,
    ): array {
        if (! $calculation || ! $this->isSoapVersion($version)) {
            return [];
        }

        $rows = [];
        $selected = data_get($calculation, 'lye.selected', []);
        $lyeType = (string) ($draft['lyeType'] ?? $version->calculation_context['lye_type'] ?? data_get($selected, 'type', 'naoh'));
        $lyeTypes = match ($lyeType) {
            'dual' => ['naoh', 'koh'],
            'koh' => ['koh'],
            default => ['naoh'],
        };
        foreach ($lyeTypes as $index => $type) {
            try {
                $ingredient = $this->alkaliResolver->resolve($type);
            } catch (ValidationException) {
                $ingredient = null;
            }
            $weight = $type === 'koh'
                ? data_get($selected, 'koh_to_weigh', 0)
                : data_get($selected, 'naoh_weight', 0);
            $weight = $this->decimal($weight) ?? '0';

            if (bccomp($weight, '0', 18) <= 0) {
                continue;
            }

            $rows[] = [
                'ingredient_id' => $ingredient instanceof Ingredient ? (int) $ingredient->id : 0,
                'position' => $index + 1,
                'phase_key' => 'lye_alkali',
                'phase_name' => __('workbench.costing.phases.alkali'),
                'name' => $ingredient instanceof Ingredient
                    ? ($type === 'koh'
                        ? __('workbench.costing.ingredients.koh_with_purity', [
                            'name' => $ingredient->localizedDisplayName() ?? $ingredient->display_name,
                            'purity' => NumberLocale::formatDecimal(
                                $draft['kohPurity'] ?? $version->calculation_context['koh_purity_percentage'] ?? 90,
                                0,
                                app()->getLocale(),
                            ),
                        ])
                        : ($ingredient->localizedDisplayName() ?? $ingredient->display_name))
                    : __('formula_documents.ingredients.'.$type),
                'percentage' => '0',
                'weight' => $weight,
                'is_derived' => true,
            ];
        }

        $substitutions = $version->phases
            ->firstWhere('slug', 'lye_water')
            ?->items
            ->map(fn ($item): array => ['percentage' => (string) $item->percentage])
            ->values()
            ->all() ?? [];
        $totalLiquidWeight = data_get($calculation, 'lye.water.weight', 0);
        $allocation = $this->lyeLiquidAllocationService->allocateFresh($totalLiquidWeight, $substitutions);
        $waterWeight = $this->decimal($allocation['water_weight'] ?? 0) ?? '0';
        $liquidProcess = data_get($calculation, 'liquid_process');

        // A liquid soap also buys the water of its dilutions and neutralizer solution.
        if (is_array($liquidProcess) && ($liquidProcess['status'] ?? null) === 'ok') {
            $waterWeight = bcadd($waterWeight, $this->liquidSoapFormulaProcessor->addedDilutionWaterWeight($liquidProcess), 18);
        }

        if (bccomp($waterWeight, '0', 18) > 0) {
            $waterCostingItem = $costing->items->first(fn (RecipeVersionCostingItem $item): bool => $item->phase_key === 'implicit_water');
            $waterIngredient = $waterCostingItem?->ingredient ?? $this->plainWaterIngredient();

            if ($waterIngredient instanceof Ingredient) {
                $rows[] = [
                    'ingredient_id' => (int) $waterIngredient->id,
                    'position' => 1,
                    'phase_key' => 'implicit_water',
                    'phase_name' => __('workbench.costing.phases.implicit_water'),
                    'name' => $waterIngredient->localizedDisplayName() ?? $waterIngredient->display_name,
                    'percentage' => '0',
                    'weight' => $waterWeight,
                    'is_derived' => true,
                ];
            }
        }

        return $rows;
    }

    private function plainWaterIngredient(): ?Ingredient
    {
        return Ingredient::query()
            ->where('is_active', true)
            ->where('category', IngredientCategory::WaterSolventsCarriers->value)
            ->where('subcategory', IngredientSubcategory::Water->value)
            ->whereNull('owner_type')
            ->whereNull('owner_id')
            ->whereNull('workspace_id')
            ->orderBy('id')
            ->first();
    }

    /** @param list<array<string, mixed>> $syntheticRows
     * @return list<array<string, mixed>>
     */
    private function savedCostingOnlyRows(RecipeVersion $version, RecipeVersionCosting $costing, array $syntheticRows): array
    {
        $formulaKeys = [];
        foreach ($version->phases as $phase) {
            foreach ($phase->items as $item) {
                $formulaKeys[$this->lotKey((int) $item->ingredient_id, (string) $phase->slug, (int) $item->position)] = true;
            }
        }

        foreach ($syntheticRows as $row) {
            $formulaKeys[$this->lotKey(
                (int) ($row['ingredient_id'] ?? 0),
                (string) ($row['phase_key'] ?? ''),
                (int) ($row['position'] ?? 0),
            )] = true;
        }

        $rows = [];
        foreach ($costing->items as $item) {
            $identity = $this->lotKey((int) $item->ingredient_id, $item->phase_key, (int) $item->position);

            if (isset($formulaKeys[$identity])) {
                continue;
            }

            $phase = $version->phases->firstWhere('slug', $item->phase_key);
            $rows[] = [
                'ingredient_id' => (int) $item->ingredient_id,
                'position' => (int) $item->position,
                'phase_key' => $item->phase_key,
                'phase_name' => $phase instanceof RecipePhase
                    ? $this->phaseName($phase)
                    : str((string) $item->phase_key)->replace('_', ' ')->title()->toString(),
                'name' => $item->ingredient?->localizedDisplayName() ?? $item->ingredient?->display_name ?? 'Ingredient',
                'percentage' => '0',
                'weight' => '0',
                'is_derived' => true,
            ];
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function savedPackagingRows(RecipeVersion $version, RecipeVersionCosting $costing): array
    {
        $rows = $costing->packagingItems
            ->values()
            ->map(fn (RecipeVersionCostingPackagingItem $item, int $index): array => [
                'packaging_item_id' => $item->packaging_item_id === null ? null : (int) $item->packaging_item_id,
                'position' => $index + 1,
                'name' => $item->name,
                'unit_cost' => (string) $item->unit_cost,
                'components_per_unit' => (string) $item->quantity,
            ])
            ->all();
        $savedOccurrences = [];

        foreach ($rows as $row) {
            $identity = $this->packagingIdentity($row);
            $savedOccurrences[$identity] = ($savedOccurrences[$identity] ?? 0) + 1;
        }

        $formulaOccurrences = [];
        foreach ($version->packagingItems as $item) {
            $formulaRow = [
                'packaging_item_id' => $item->packaging_item_id === null ? null : (int) $item->packaging_item_id,
                'position' => (int) $item->position,
                'name' => $item->name,
            ];
            $identity = $this->packagingIdentity($formulaRow);
            $occurrence = $formulaOccurrences[$identity] ?? 0;
            $formulaOccurrences[$identity] = $occurrence + 1;

            if ($occurrence < ($savedOccurrences[$identity] ?? 0)) {
                continue;
            }

            $rows[] = [
                ...$formulaRow,
                'unit_cost' => $item->packagingItem?->unit_cost === null ? null : (string) $item->packagingItem->unit_cost,
                'components_per_unit' => (string) $item->components_per_unit,
            ];
        }

        return $rows;
    }

    private function isSoapVersion(RecipeVersion $version): bool
    {
        $recipe = Recipe::query()->withoutGlobalScopes()->find($version->recipe_id);

        return $recipe?->productFamily()->withoutGlobalScopes()->first()?->usesSoapCalculation() ?? false;
    }

    /** @param array<string, mixed> $preview
     * @return array<string, mixed>
     */
    private function legacyPreviewShape(array $preview): array
    {
        $preview['ingredient_rows'] = array_map(fn (array $row): array => [
            ...$row,
            'line_cost' => $row['line_cost'] ?? 0.0,
        ], $preview['ingredient_rows']);
        $preview['packaging_rows'] = array_map(fn (array $row): array => [
            ...$row,
            'cost_per_finished_unit' => $row['cost_per_finished_unit'] ?? 0.0,
            'line_cost' => $row['line_cost'] ?? 0.0,
        ], $preview['packaging_rows']);

        if ($preview['packaging_total'] === null) {
            $preview['packaging_total'] = 0.0;
            $preview['total_cost'] = $preview['ingredient_total'];
        }

        return $preview;
    }

    /**
     * A liquid soap version's additions cost the weight their share of the
     * finished batch gives them in the recalculated snapshot.
     *
     * @param  list<array<string, mixed>>  $syntheticRows
     * @param  array<string, mixed>|null  $snapshot
     * @return array<string, mixed>
     */
    private function transientFormulaForVersion(RecipeVersion $version, string $family, array $syntheticRows, ?array $snapshot = null): array
    {
        $phases = $version->phases
            ->mapWithKeys(fn (RecipePhase $phase): array => [
                $phase->slug => [
                    'label' => $this->phaseName($phase),
                    'rows' => $phase->items
                        ->map(fn ($item): array => [
                            'ingredient_id' => (int) $item->ingredient_id,
                            'position' => (int) $item->position,
                            'name' => $item->ingredient?->display_name ?? 'Ingredient',
                            'percentage' => (string) $item->percentage,
                            'weight' => (string) $item->weight,
                        ])
                        ->all(),
                ],
            ])
            ->all();
        $liquidProcess = data_get($snapshot, 'calculation.liquid_process');

        if (is_array($liquidProcess) && ($liquidProcess['status'] ?? null) === 'ok') {
            $sized = $this->liquidSoapFormulaProcessor->withAdditionWeights(
                collect($phases)->map(fn (array $phase): array => $phase['rows'])->all(),
                $liquidProcess,
                data_get($snapshot, 'draft.oilWeight', $version->batch_size),
            );

            foreach (RecipeWorkbenchPhaseBlueprints::ADDITION_PHASES as $phaseKey) {
                if (isset($phases[$phaseKey])) {
                    $phases[$phaseKey]['rows'] = collect($sized[$phaseKey])
                        ->map(fn (array $row): array => [...$row, 'percentage' => sprintf('%.12F', $row['percentage']), 'weight' => sprintf('%.12F', $row['weight'])])
                        ->all();
                }
            }
        }

        return [
            'family' => $family,
            'basis_weight' => (string) $version->batch_size,
            'basis_unit' => $version->batch_unit ?: 'g',
            'phases' => $phases,
            'packaging_items' => $version->packagingItems
                ->map(fn (RecipeVersionPackagingItem $item): array => [
                    'packaging_item_id' => $item->packaging_item_id === null ? null : (int) $item->packaging_item_id,
                    'position' => (int) $item->position,
                    'name' => $item->name,
                    'material_code' => $item->packagingItem?->material_code,
                    'components_per_unit' => (string) $item->components_per_unit,
                ])
                ->all(),
            'synthetic_rows' => $syntheticRows,
        ];
    }

    /** @return array<string, mixed> */
    private function transientCostingForProjection(
        RecipeVersionCosting $costing,
        string|int|float $basisWeight,
        string $basisUnit,
        ?string $unitsProduced,
        string $currency,
    ): array {
        return [
            'oil_weight_for_costing' => $basisWeight,
            'oil_unit_for_costing' => $basisUnit,
            'units_produced' => $unitsProduced,
            'currency' => $currency,
            'items' => $costing->items
                ->map(fn (RecipeVersionCostingItem $item): array => [
                    'ingredient_id' => (int) $item->ingredient_id,
                    'phase_key' => $item->phase_key,
                    'position' => (int) $item->position,
                    'price_per_kg' => $item->price_per_kg === null ? null : (string) $item->price_per_kg,
                ])
                ->all(),
            'packaging_items' => $costing->packagingItems
                ->values()
                ->map(fn (RecipeVersionCostingPackagingItem $item, int $index): array => [
                    'packaging_item_id' => $item->packaging_item_id === null ? null : (int) $item->packaging_item_id,
                    'position' => $index + 1,
                    'name' => $item->name,
                    'unit_cost' => (string) $item->unit_cost,
                    'components_per_unit' => (string) $item->quantity,
                ])
                ->all(),
        ];
    }

    /** Rows whose percentage is not a % of oils: they cost their stored weight. */
    private function costsStoredWeight(string $phaseKey): bool
    {
        return in_array($phaseKey, ['lye_water', ...RecipeWorkbenchPhaseBlueprints::DILUTION_PHASES], true);
    }

    public function lotKey(int $ingredientId, string $phaseKey, int $position): string
    {
        return implode(':', [$ingredientId, $phaseKey, $position]);
    }

    private function phaseName(RecipePhase $phase): string
    {
        return match ($phase->slug) {
            'saponified_oils' => 'Saponified oils',
            'lye_water' => __('workbench.costing.phases.lye_liquid'),
            'first_dilution' => __('workbench.costing.phases.first_dilution'),
            'final_dilution' => __('workbench.costing.phases.final_dilution'),
            'additives' => 'Additives',
            'fragrance' => 'Fragrance and aromatics',
            default => $phase->name,
        };
    }
}
