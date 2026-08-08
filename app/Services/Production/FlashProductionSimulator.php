<?php

namespace App\Services\Production;

use App\Enums\MassUnit;
use App\Enums\ProductionBasisKind;
use App\Models\CurrentMaterialPrice;
use App\Models\Ingredient;
use App\Models\PackagingItem;
use App\Models\ProductionBatchPreset;
use App\Models\ProductionTaskSet;
use App\Models\ProductionTaskSetItem;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\Workspace;
use App\Services\MassConverter;
use App\Support\NumberLocale;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class FlashProductionSimulator
{
    /** @var array<int, ?Recipe> */
    private array $recipesById = [];

    /** @var array<int, ?RecipeVersion> */
    private array $versionsByRecipeId = [];

    /** @var array<int, ?ProductionTaskSet> */
    private array $taskSetsById = [];

    /** @var array<string, bool> */
    private array $taskSetRecipeApplicability = [];

    public function __construct(
        private readonly MassConverter $massConverter,
        private readonly ProductionRequirementBuilder $requirementBuilder,
        private readonly FlashProductionLimits $limits,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array{lines: list<array<string, mixed>>, requirements: list<array<string, mixed>>, totals: array<string, mixed>}
     */
    public function simulate(Workspace $workspace, array $lines): array
    {
        $simulationLines = [];
        $requirements = collect();
        $subjects = [];
        $taskMinutes = 0;
        $totalWholeBatches = 0;

        foreach ($lines as $index => $input) {
            $line = $this->normalizeLine($workspace, $input, $index);
            $recipe = $this->recipe($workspace, $line['recipe_id']);

            if (! $recipe instanceof Recipe) {
                throw ValidationException::withMessages([
                    "lines.{$index}.recipe_id" => 'Choose a product from this workspace.',
                ]);
            }

            $version = $this->version($workspace, $recipe);

            if (! $version instanceof RecipeVersion) {
                throw ValidationException::withMessages([
                    "lines.{$index}.recipe_id" => 'Choose a product with a published formula.',
                ]);
            }

            $basisKind = $recipe->productFamily?->calculation_basis === 'total_formula'
                ? ProductionBasisKind::TotalFormulaMass
                : ProductionBasisKind::OilMass;
            $basisQuantityGrams = $this->massConverter->toGrams($line['basis_input_value'], $line['basis_input_unit']);
            $wholeBatches = (int) ceil($line['desired_units'] / $line['expected_units_per_batch']);
            $totalWholeBatches += $wholeBatches;
            $this->limits->assertWithinLimit($totalWholeBatches);
            $expectedUnits = $wholeBatches * $line['expected_units_per_batch'];
            $extraUnits = $expectedUnits - $line['desired_units'];
            $batchRequirements = $this->requirementBuilder->build(
                version: $version,
                basisKind: $basisKind,
                basisQuantityGrams: $basisQuantityGrams,
                expectedUnits: $line['expected_units_per_batch'],
                recipe: $recipe,
            );

            foreach ($batchRequirements as $requirement) {
                $key = $requirement['ingredient_id'] !== null
                    ? 'ingredient:'.$requirement['ingredient_id']
                    : 'packaging:'.$requirement['packaging_item_id'];
                $existing = $requirements->get($key);
                $quantity = $requirement['ingredient_id'] !== null
                    ? (string) $requirement['required_mass_grams']
                    : (string) $requirement['required_units'];

                if ($existing === null) {
                    $subjects[$key] ??= $this->subjectFromVersion($version, $requirement);
                    $requirements->put($key, [
                        'key' => $key,
                        'ingredient_id' => $requirement['ingredient_id'],
                        'packaging_item_id' => $requirement['packaging_item_id'],
                        'kind' => $requirement['kind'],
                        'subject_name' => $requirement['subject_name_snapshot'],
                        'required_canonical' => $this->zeroAdd('0'),
                        'unit' => $requirement['ingredient_id'] !== null ? 'g' : 'unit',
                    ]);
                    $existing = $requirements->get($key);
                }

                $existing['required_canonical'] = bcadd(
                    (string) $existing['required_canonical'],
                    bcmul($quantity, (string) $wholeBatches, 9),
                    9,
                );
                $requirements->put($key, $existing);
            }

            $taskSet = $this->taskSet($workspace, $recipe, $line['task_set_id'], $index);
            $lineTaskMinutes = $taskSet instanceof ProductionTaskSet
                ? (int) $taskSet->items->sum(
                    fn (ProductionTaskSetItem $item): int => (int) ($item->duration_minutes ?? $item->taskType?->default_duration_minutes ?? 0),
                )
                : 0;
            $taskMinutes += $lineTaskMinutes * $wholeBatches;

            $simulationLines[] = [
                'line_index' => $index,
                'recipe_id' => $recipe->id,
                'recipe' => $recipe,
                'recipe_version_id' => $version->id,
                'desired_units' => $line['desired_units'],
                'expected_units_per_batch' => $line['expected_units_per_batch'],
                'whole_batches' => $wholeBatches,
                'expected_units' => $expectedUnits,
                'extra_units' => $extraUnits,
                'basis_input_value' => $line['basis_input_value'],
                'basis_input_unit' => $line['basis_input_unit'],
                'basis_quantity_grams' => $basisQuantityGrams,
                'basis_kind' => $basisKind,
                'task_set_id' => $taskSet?->id,
                'task_set' => $taskSet,
                'task_minutes' => $lineTaskMinutes,
            ];
        }

        $prices = $this->pricesByKey($workspace, $requirements);
        $displayMassUnit = $workspace->mass_display_system->priceUnit();
        $priceCurrencies = collect($prices)->pluck('currency')->filter()->unique()->values();
        $budgetCurrency = $priceCurrencies->count() === 1
            ? (string) $priceCurrencies->first()
            : null;
        $budget = '0';
        $missingPrices = 0;

        $requirementRows = $requirements->map(function (array $requirement) use ($subjects, $prices, $displayMassUnit, &$budget, &$missingPrices): array {
            $subject = $subjects[$requirement['key']] ?? null;

            if (! $subject instanceof Ingredient && ! $subject instanceof PackagingItem) {
                throw ValidationException::withMessages([
                    'requirements' => 'A simulated material is no longer available.',
                ]);
            }

            $required = (string) $requirement['required_canonical'];
            $price = $prices[$requirement['key']] ?? null;
            $canonicalUnitPrice = $price?->price_per_canonical_unit;
            $estimatedCost = null;

            if ($canonicalUnitPrice === null) {
                $missingPrices++;
            } else {
                $estimatedCost = bcmul($required, (string) $canonicalUnitPrice, 9);
                $budget = bcadd($budget, $estimatedCost, 9);
            }

            return [
                ...$requirement,
                'subject' => $subject,
                'required' => $required,
                'required_display' => $requirement['ingredient_id'] !== null
                    ? $this->massConverter->fromGrams($required, $displayMassUnit)
                    : $required,
                'display_unit' => $requirement['ingredient_id'] !== null
                    ? $displayMassUnit->value
                    : 'unit',
                'unit_price' => $canonicalUnitPrice,
                'display_unit_price' => $canonicalUnitPrice === null
                    ? null
                    : ($requirement['ingredient_id'] !== null
                        ? bcmul((string) $canonicalUnitPrice, $displayMassUnit->gramsPerUnit(), 9)
                        : $canonicalUnitPrice),
                'price_currency' => $price?->currency,
                'estimated_cost' => $estimatedCost,
            ];
        })->values()->all();

        return [
            'lines' => $simulationLines,
            'requirements' => $requirementRows,
            'totals' => [
                'desired_units' => $this->sum($simulationLines, 'desired_units'),
                'expected_units' => $this->sum($simulationLines, 'expected_units'),
                'extra_units' => $this->sum($simulationLines, 'extra_units'),
                'whole_batches' => $this->sum($simulationLines, 'whole_batches'),
                'task_minutes' => $taskMinutes,
                'budget' => $missingPrices === 0 && $budgetCurrency !== null ? $budget : null,
                'budget_currency' => $budgetCurrency,
                'missing_prices' => $missingPrices,
            ],
        ];
    }

    private function recipe(Workspace $workspace, int $recipeId): ?Recipe
    {
        if (! array_key_exists($recipeId, $this->recipesById)) {
            $this->recipesById[$recipeId] = Recipe::withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->whereNull('archived_at')
                ->with('productFamily')
                ->find($recipeId);
        }

        return $this->recipesById[$recipeId];
    }

    private function version(Workspace $workspace, Recipe $recipe): ?RecipeVersion
    {
        if (! array_key_exists($recipe->id, $this->versionsByRecipeId)) {
            $this->versionsByRecipeId[$recipe->id] = RecipeVersion::withoutGlobalScopes()
                ->where('recipe_id', $recipe->id)
                ->where('workspace_id', $workspace->id)
                ->where('is_current', false)
                ->orderByDesc('version_number')
                ->orderByDesc('id')
                ->first();
        }

        return $this->versionsByRecipeId[$recipe->id];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{recipe_id: int, desired_units: int, expected_units_per_batch: int, basis_input_value: string, basis_input_unit: MassUnit, task_set_id: ?int}
     */
    private function normalizeLine(Workspace $workspace, array $input, int $index): array
    {
        $preset = null;
        $recipeId = (int) ($input['recipe_id'] ?? 0);

        if (filled($input['preset_id'] ?? null)) {
            $preset = ProductionBatchPreset::query()
                ->where('workspace_id', $workspace->id)
                ->where('is_active', true)
                ->find($input['preset_id']);

            if (! $preset instanceof ProductionBatchPreset) {
                throw ValidationException::withMessages([
                    "lines.{$index}.preset_id" => 'Choose an active batch preset from this workspace.',
                ]);
            }

            if ($recipeId < 1) {
                throw ValidationException::withMessages([
                    "lines.{$index}.recipe_id" => 'Choose a product before choosing a batch size.',
                ]);
            }

            if (! $preset->recipes()->whereKey($recipeId)->exists()) {
                throw ValidationException::withMessages([
                    "lines.{$index}.preset_id" => 'Choose a batch size applicable to this product.',
                ]);
            }
        }

        $desiredUnits = $this->positiveWhole($input['desired_units'] ?? null, "lines.{$index}.desired_units");
        $expectedUnits = $this->positiveWhole($input['expected_units_per_batch'] ?? $preset?->expected_units, "lines.{$index}.expected_units_per_batch");
        $basisValue = NumberLocale::normalizeDecimalString($input['basis_input_value'] ?? $preset?->basis_input_value ?? '');
        $basisUnitInput = $input['basis_input_unit'] ?? $preset?->basis_input_unit?->value;

        if ($recipeId < 1 || $basisValue === null || $basisUnitInput === null) {
            throw ValidationException::withMessages([
                "lines.{$index}" => 'Enter a product, batch quantity, and expected units per batch.',
            ]);
        }

        if (preg_match('/^\d+(?:\.\d+)?$/', $basisValue) !== 1 || bccomp($basisValue, '0', 18) <= 0) {
            throw ValidationException::withMessages([
                "lines.{$index}.basis_input_value" => 'The batch quantity must be greater than zero.',
            ]);
        }

        try {
            $basisUnit = $basisUnitInput instanceof MassUnit ? $basisUnitInput : MassUnit::fromInput((string) $basisUnitInput);
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages([
                "lines.{$index}.basis_input_unit" => 'Choose a supported mass unit.',
            ]);
        }

        return [
            'recipe_id' => $recipeId,
            'desired_units' => $desiredUnits,
            'expected_units_per_batch' => $expectedUnits,
            'basis_input_value' => $basisValue,
            'basis_input_unit' => $basisUnit,
            'task_set_id' => filled($input['task_set_id'] ?? null) ? (int) $input['task_set_id'] : null,
        ];
    }

    private function positiveWhole(mixed $value, string $field): int
    {
        $normalized = trim((string) $value);

        if (preg_match('/^[1-9]\d*$/', $normalized) !== 1) {
            throw ValidationException::withMessages([
                $field => 'Enter a positive whole number.',
            ]);
        }

        return (int) $normalized;
    }

    private function taskSet(Workspace $workspace, Recipe $recipe, ?int $taskSetId, int $index): ?ProductionTaskSet
    {
        if ($taskSetId === null) {
            return null;
        }

        if (array_key_exists($taskSetId, $this->taskSetsById)) {
            $taskSet = $this->taskSetsById[$taskSetId];
        } else {
            $taskSet = ProductionTaskSet::query()
                ->where('workspace_id', $workspace->id)
                ->where('is_active', true)
                ->with('items.taskType')
                ->find($taskSetId);

            if ($taskSet instanceof ProductionTaskSet) {
                $this->taskSetsById[$taskSetId] = $taskSet;
            } else {
                $this->taskSetsById[$taskSetId] = null;
            }
        }

        if (! $taskSet instanceof ProductionTaskSet) {
            throw ValidationException::withMessages([
                "lines.{$index}.task_set_id" => 'Choose an active task set from this workspace.',
            ]);
        }

        $applicabilityKey = $taskSet->id.':'.$recipe->id;

        if (! array_key_exists($applicabilityKey, $this->taskSetRecipeApplicability)) {
            $this->taskSetRecipeApplicability[$applicabilityKey] = $taskSet->recipes()->whereKey($recipe->id)->exists();
        }

        if (! $this->taskSetRecipeApplicability[$applicabilityKey]) {
            throw ValidationException::withMessages([
                "lines.{$index}.task_set_id" => 'Choose a task set applicable to this product.',
            ]);
        }

        return $taskSet;
    }

    /** @param list<array<string, mixed>> $lines */
    private function sum(array $lines, string $key): int
    {
        return array_sum(array_map(fn (array $line): int => (int) $line[$key], $lines));
    }

    private function zeroAdd(string $value): string
    {
        return bcadd($value, '0', 9);
    }

    /** @param array<string, mixed> $requirement */
    private function subjectFromVersion(RecipeVersion $version, array $requirement): Ingredient|PackagingItem|null
    {
        if ($requirement['ingredient_id'] !== null) {
            foreach ($version->phases as $phase) {
                $item = $phase->items->firstWhere('ingredient_id', $requirement['ingredient_id']);

                if ($item?->ingredient instanceof Ingredient) {
                    return $item->ingredient;
                }
            }
        }

        if ($requirement['packaging_item_id'] !== null) {
            return $version->packagingItems
                ->firstWhere('packaging_item_id', $requirement['packaging_item_id'])
                ?->packagingItem;
        }

        return null;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $requirements
     * @return array<string, CurrentMaterialPrice>
     */
    private function pricesByKey(Workspace $workspace, Collection $requirements): array
    {
        $ingredientIds = $requirements->pluck('ingredient_id')->filter()->unique()->values()->all();
        $packagingItemIds = $requirements->pluck('packaging_item_id')->filter()->unique()->values()->all();

        if ($ingredientIds === [] && $packagingItemIds === []) {
            return [];
        }

        return CurrentMaterialPrice::query()
            ->where('workspace_id', $workspace->id)
            ->where(function ($query) use ($ingredientIds, $packagingItemIds): void {
                $query->whereIn('ingredient_id', $ingredientIds)
                    ->orWhereIn('packaging_item_id', $packagingItemIds);
            })
            ->get()
            ->mapWithKeys(fn (CurrentMaterialPrice $price): array => [
                $price->ingredient_id !== null
                    ? 'ingredient:'.$price->ingredient_id
                    : 'packaging:'.$price->packaging_item_id => $price,
            ])
            ->all();
    }
}
