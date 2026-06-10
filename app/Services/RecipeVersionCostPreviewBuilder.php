<?php

namespace App\Services;

use App\Models\Recipe;
use App\Models\RecipePhase;
use App\Models\RecipeVersion;
use App\Models\RecipeVersionCosting;
use App\Models\RecipeVersionCostingItem;
use App\Models\RecipeVersionCostingPackagingItem;
use App\Models\RecipeVersionPackagingItem;
use App\Models\User;
use Illuminate\Support\Collection;

class RecipeVersionCostPreviewBuilder
{
    public function __construct(
        private readonly RecipeVersionCostingSynchronizer $costingSynchronizer,
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
    public function build(Recipe $recipe, RecipeVersion $version, User $user, float $batchBasisValue, ?int $unitsProduced): array
    {
        $existingCosting = RecipeVersionCosting::query()
            ->with(['items', 'packagingItems'])
            ->where('recipe_version_id', $version->id)
            ->where('user_id', $user->id)
            ->first();

        $costing = $this->costingSynchronizer->ensureCosting($version, $user);
        $currency = $costing->currency ?: $user->defaultCurrency();
        $unit = $version->batch_unit ?: 'g';

        $version = RecipeVersion::withoutGlobalScopes()
            ->with([
                'phases' => fn ($query) => $query->withoutGlobalScopes()->orderBy('sort_order'),
                'phases.items' => fn ($query) => $query->withoutGlobalScopes()->with('ingredient')->orderBy('position'),
                'packagingItems' => fn ($query) => $query->withoutGlobalScopes()->with('packagingItem')->orderBy('position'),
            ])
            ->findOrFail($version->id);

        $this->preserveExistingUnplannedPackagingRows($costing, $version, $existingCosting);

        $costing = $costing->fresh(['items.ingredient', 'packagingItems.packagingItem']) ?? $costing->load(['items.ingredient', 'packagingItems.packagingItem']);
        $ingredientPricesByKey = $costing->items->keyBy(fn (RecipeVersionCostingItem $item): string => $this->lotKey(
            (int) $item->ingredient_id,
            $item->phase_key,
            (int) $item->position,
        ));

        $ingredientRows = $this->ingredientRows($version, $ingredientPricesByKey, $batchBasisValue, $unit);
        $packagingRows = $this->packagingRows($version, $costing, $existingCosting, $unitsProduced);

        $ingredientTotal = round((float) collect($ingredientRows)->sum(fn (array $row): float => (float) $row['line_cost']), 4);
        $packagingTotal = round((float) collect($packagingRows)->sum(fn (array $row): float => (float) $row['line_cost']), 4);
        $totalCost = round($ingredientTotal + $packagingTotal, 4);
        $costPerUnit = $unitsProduced !== null && $unitsProduced > 0
            ? round($totalCost / $unitsProduced, 4)
            : null;

        return [
            'currency' => $currency,
            'ingredient_rows' => $ingredientRows,
            'packaging_rows' => $packagingRows,
            'ingredient_total' => $ingredientTotal,
            'packaging_total' => $packagingTotal,
            'total_cost' => $totalCost,
            'cost_per_unit' => $costPerUnit,
            'has_unpriced_rows' => collect($ingredientRows)
                ->merge($packagingRows)
                ->contains(fn (array $row): bool => (bool) $row['is_unpriced']),
        ];
    }

    public function lotKey(int $ingredientId, string $phaseKey, int $position): string
    {
        return implode(':', [$ingredientId, $phaseKey, $position]);
    }

    /**
     * @param  Collection<string, RecipeVersionCostingItem>  $ingredientPricesByKey
     * @return array<int, array<string, mixed>>
     */
    private function ingredientRows(RecipeVersion $version, Collection $ingredientPricesByKey, float $batchBasisValue, string $unit): array
    {
        return $version->phases
            ->flatMap(fn (RecipePhase $phase): Collection => $phase->items
                ->map(function ($item) use ($batchBasisValue, $ingredientPricesByKey, $phase, $unit): array {
                    $position = (int) $item->position;
                    $phaseKey = (string) $phase->slug;
                    $ingredientId = (int) $item->ingredient_id;
                    $lotKey = $this->lotKey($ingredientId, $phaseKey, $position);
                    $costingItem = $ingredientPricesByKey->get($lotKey);
                    $pricePerKg = $costingItem?->price_per_kg === null ? null : (float) $costingItem->price_per_kg;
                    $percentage = (float) $item->percentage;
                    $quantity = round($batchBasisValue * ($percentage / 100), 4);

                    return [
                        'lot_key' => $lotKey,
                        'ingredient_id' => $ingredientId,
                        'phase_key' => $phaseKey,
                        'phase_name' => $this->phaseName($phase),
                        'position' => $position,
                        'ingredient_name' => $item->ingredient?->display_name ?? 'Ingredient',
                        'percentage' => $percentage,
                        'quantity' => $quantity,
                        'unit' => $unit,
                        'price_per_kg' => $pricePerKg,
                        'line_cost' => $pricePerKg === null ? 0.0 : round(($quantity / 1000) * $pricePerKg, 4),
                        'is_unpriced' => $pricePerKg === null,
                    ];
                }))
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function packagingRows(RecipeVersion $version, RecipeVersionCosting $costing, ?RecipeVersionCosting $existingCosting, ?int $unitsProduced): array
    {
        $costingRowsByKey = $costing->packagingItems->keyBy(fn (RecipeVersionCostingPackagingItem $item): string => $this->packagingKey(
            $item->user_packaging_item_id === null ? null : (int) $item->user_packaging_item_id,
            $item->name,
        ));

        $rows = $version->packagingItems
            ->toBase()
            ->map(function (RecipeVersionPackagingItem $item) use ($costingRowsByKey, $unitsProduced): array {
                $costingItem = $costingRowsByKey->get($this->packagingKey(
                    $item->user_packaging_item_id === null ? null : (int) $item->user_packaging_item_id,
                    $item->name,
                ));

                return $this->packagingRow(
                    packagingItemId: $item->user_packaging_item_id === null ? null : (int) $item->user_packaging_item_id,
                    position: (int) $item->position,
                    name: $item->name,
                    componentsPerUnit: $costingItem?->quantity === null ? (float) $item->components_per_unit : (float) $costingItem->quantity,
                    unitCost: $costingItem?->unit_cost === null
                        ? ($item->packagingItem?->unit_cost === null ? null : (float) $item->packagingItem->unit_cost)
                        : (float) $costingItem->unit_cost,
                    unitsProduced: $unitsProduced,
                );
            });

        $plannedKeys = $version->packagingItems
            ->toBase()
            ->map(fn (RecipeVersionPackagingItem $item): string => $this->packagingKey(
                $item->user_packaging_item_id === null ? null : (int) $item->user_packaging_item_id,
                $item->name,
            ));

        $legacyRows = ($existingCosting?->packagingItems ?? collect())
            ->reject(fn (RecipeVersionCostingPackagingItem $item): bool => $plannedKeys->contains($this->packagingKey(
                $item->user_packaging_item_id === null ? null : (int) $item->user_packaging_item_id,
                $item->name,
            )))
            ->values()
            ->map(fn (RecipeVersionCostingPackagingItem $item, int $index): array => $this->packagingRow(
                packagingItemId: $item->user_packaging_item_id === null ? null : (int) $item->user_packaging_item_id,
                position: $version->packagingItems->count() + $index + 1,
                name: $item->name,
                componentsPerUnit: (float) $item->quantity,
                unitCost: (float) $item->unit_cost,
                unitsProduced: $unitsProduced,
            ));

        return $rows->merge($legacyRows)->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function packagingRow(?int $packagingItemId, int $position, string $name, float $componentsPerUnit, ?float $unitCost, ?int $unitsProduced): array
    {
        $costPerFinishedUnit = $unitCost === null ? 0.0 : round($unitCost * $componentsPerUnit, 4);

        return [
            'user_packaging_item_id' => $packagingItemId,
            'position' => $position,
            'name' => $name,
            'components_per_unit' => $componentsPerUnit,
            'unit_cost' => $unitCost,
            'cost_per_finished_unit' => $costPerFinishedUnit,
            'line_cost' => $unitsProduced === null ? 0.0 : round($costPerFinishedUnit * $unitsProduced, 4),
            'is_unpriced' => $unitCost === null,
        ];
    }

    private function packagingKey(?int $packagingItemId, string $name): string
    {
        return ($packagingItemId ?? 'unlinked').':'.mb_strtolower($name);
    }

    private function preserveExistingUnplannedPackagingRows(RecipeVersionCosting $costing, RecipeVersion $version, ?RecipeVersionCosting $existingCosting): void
    {
        if (! $existingCosting instanceof RecipeVersionCosting) {
            return;
        }

        $plannedKeys = $version->packagingItems
            ->toBase()
            ->map(fn (RecipeVersionPackagingItem $item): string => $this->packagingKey(
                $item->user_packaging_item_id === null ? null : (int) $item->user_packaging_item_id,
                $item->name,
            ));

        $currentKeys = $costing->packagingItems
            ->toBase()
            ->map(fn (RecipeVersionCostingPackagingItem $item): string => $this->packagingKey(
                $item->user_packaging_item_id === null ? null : (int) $item->user_packaging_item_id,
                $item->name,
            ));

        $existingCosting->packagingItems
            ->reject(fn (RecipeVersionCostingPackagingItem $item): bool => $plannedKeys->contains($this->packagingKey(
                $item->user_packaging_item_id === null ? null : (int) $item->user_packaging_item_id,
                $item->name,
            )))
            ->reject(fn (RecipeVersionCostingPackagingItem $item): bool => $currentKeys->contains($this->packagingKey(
                $item->user_packaging_item_id === null ? null : (int) $item->user_packaging_item_id,
                $item->name,
            )))
            ->each(function (RecipeVersionCostingPackagingItem $item) use ($costing): void {
                $costing->packagingItems()->create([
                    'user_packaging_item_id' => $item->user_packaging_item_id,
                    'name' => $item->name,
                    'unit_cost' => $item->unit_cost,
                    'quantity' => $item->quantity,
                ]);
            });
    }

    private function phaseName(RecipePhase $phase): string
    {
        return match ($phase->slug) {
            'saponified_oils' => 'Saponified oils',
            'additives' => 'Additives',
            'fragrance' => 'Fragrance and aromatics',
            default => $phase->name,
        };
    }
}
