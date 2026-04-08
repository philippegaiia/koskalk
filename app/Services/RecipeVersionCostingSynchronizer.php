<?php

namespace App\Services;

use App\Models\Recipe;
use App\Models\RecipePhase;
use App\Models\RecipeVersion;
use App\Models\RecipeVersionCosting;
use App\Models\RecipeVersionCostingItem;
use App\Models\RecipeVersionCostingPackagingItem;
use App\Models\User;
use App\Models\UserIngredientPrice;
use App\Models\UserPackagingItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Keeps formula costing rows in step with the draft version while preserving
 * the user's remembered defaults and any formula-specific overrides.
 */
class RecipeVersionCostingSynchronizer
{
    /**
     * Costing rows follow the draft version the user is editing, while the packaging
     * catalog stays available even before the formula has been saved for the first time.
     *
     * @return array<string, mixed>
     */
    public function payload(?Recipe $recipe, ?User $user): array
    {
        $packagingCatalog = $user instanceof User
            ? $this->packagingCatalogPayload($user)
            : [];

        $draftVersion = $recipe instanceof Recipe
            ? RecipeVersion::withoutGlobalScopes()
                ->where('recipe_id', $recipe->id)
                ->where('is_draft', true)
                ->first()
            : null;

        if (! $draftVersion instanceof RecipeVersion || ! $user instanceof User) {
            return [
                'settings' => null,
                'item_prices' => [],
                'packaging_items' => [],
                'packaging_catalog' => $packagingCatalog,
            ];
        }

        $costing = $this->ensureCosting($draftVersion, $user);

        return [
            'settings' => [
                'id' => $costing->id,
                'oilWeightForCosting' => $costing->oil_weight_for_costing === null ? null : (float) $costing->oil_weight_for_costing,
                'oilUnitForCosting' => $costing->oil_unit_for_costing,
                'unitsProduced' => $costing->units_produced,
                'currency' => $costing->currency,
            ],
            'item_prices' => $costing->items
                ->map(fn (RecipeVersionCostingItem $item): array => [
                    'ingredient_id' => $item->ingredient_id,
                    'phase_key' => $item->phase_key,
                    'position' => $item->position,
                    'price_per_kg' => $item->price_per_kg === null ? null : (float) $item->price_per_kg,
                ])
                ->values()
                ->all(),
            'packaging_items' => $costing->packagingItems
                ->map(fn (RecipeVersionCostingPackagingItem $item): array => [
                    'id' => $item->id,
                    'user_packaging_item_id' => $item->user_packaging_item_id,
                    'name' => $item->name,
                    'unit_cost' => (float) $item->unit_cost,
                    'components_per_unit' => (float) $item->quantity,
                ])
                ->values()
                ->all(),
            'packaging_catalog' => $packagingCatalog,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function save(RecipeVersion $recipeVersion, User $user, array $payload): array
    {
        return DB::transaction(function () use ($payload, $recipeVersion, $user): array {
            $costing = $this->ensureCosting($recipeVersion, $user);

            $costing->fill([
                'oil_weight_for_costing' => $this->nullableFloat($payload['oil_weight_for_costing'] ?? null),
                'oil_unit_for_costing' => $this->normalizeOilUnit($payload['oil_unit_for_costing'] ?? $costing->oil_unit_for_costing),
                'units_produced' => $this->nullableInt($payload['units_produced'] ?? null),
                'currency' => $this->normalizeCurrency($payload['currency'] ?? $costing->currency),
            ]);
            $costing->save();

            $this->syncFormulaItems($costing);
            $this->applyItemPrices($costing, $user, $payload['items'] ?? []);
            $this->replacePackagingItems($costing, $payload['packaging_items'] ?? []);

            return $this->payload($recipeVersion->recipe, $user);
        });
    }

    public function copyToVersion(?RecipeVersion $sourceVersion, RecipeVersion $targetVersion, User $user): void
    {
        if (! $sourceVersion instanceof RecipeVersion) {
            return;
        }

        $sourceCosting = RecipeVersionCosting::query()
            ->with(['items', 'packagingItems'])
            ->where('recipe_version_id', $sourceVersion->id)
            ->where('user_id', $user->id)
            ->first();

        if (! $sourceCosting instanceof RecipeVersionCosting) {
            return;
        }

        DB::transaction(function () use ($sourceCosting, $targetVersion, $user): void {
            $targetCosting = RecipeVersionCosting::query()->firstOrNew([
                'recipe_version_id' => $targetVersion->id,
                'user_id' => $user->id,
            ]);

            $targetCosting->fill([
                'oil_weight_for_costing' => $sourceCosting->oil_weight_for_costing,
                'oil_unit_for_costing' => $sourceCosting->oil_unit_for_costing,
                'units_produced' => $sourceCosting->units_produced,
                'currency' => $sourceCosting->currency,
            ]);
            $targetCosting->save();

            $this->syncFormulaItems($targetCosting);

            $sourcePricesByKey = $sourceCosting->items
                ->keyBy(fn (RecipeVersionCostingItem $item): string => $this->costingKey(
                    (int) $item->ingredient_id,
                    $item->phase_key,
                    (int) $item->position,
                ));

            $targetCosting->items->each(function (RecipeVersionCostingItem $item) use ($sourcePricesByKey): void {
                $sourceItem = $sourcePricesByKey->get($this->costingKey(
                    (int) $item->ingredient_id,
                    $item->phase_key,
                    (int) $item->position,
                ));

                if ($sourceItem instanceof RecipeVersionCostingItem) {
                    $item->price_per_kg = $sourceItem->price_per_kg;
                    $item->save();
                }
            });

            $targetCosting->packagingItems()->delete();

            $sourceCosting->packagingItems->each(function (RecipeVersionCostingPackagingItem $item) use ($targetCosting): void {
                $targetCosting->packagingItems()->create([
                    'user_packaging_item_id' => $item->user_packaging_item_id,
                    'name' => $item->name,
                    'unit_cost' => $item->unit_cost,
                    'quantity' => $item->quantity,
                ]);
            });
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function savePackagingItem(User $user, array $payload): array
    {
        $name = trim((string) ($payload['name'] ?? ''));

        if ($name === '') {
            return [
                'packaging_catalog' => $this->packagingCatalogPayload($user),
                'packaging_item' => null,
            ];
        }

        $packagingItem = UserPackagingItem::query()
            ->where('user_id', $user->id)
            ->when(
                isset($payload['id']) && is_numeric($payload['id']),
                fn ($query) => $query->whereKey((int) $payload['id']),
            )
            ->first() ?? new UserPackagingItem([
                'user_id' => $user->id,
            ]);

        $packagingItem->fill([
            'name' => $name,
            'unit_cost' => (float) ($payload['unit_cost'] ?? 0),
            'currency' => $this->normalizeCurrency($payload['currency'] ?? 'EUR'),
            'notes' => $payload['notes'] ?? null,
        ]);
        $packagingItem->save();

        return [
            'packaging_catalog' => $this->packagingCatalogPayload($user),
            'packaging_item' => [
                'id' => $packagingItem->id,
                'name' => $packagingItem->name,
                'unit_cost' => (float) $packagingItem->unit_cost,
                'currency' => $packagingItem->currency,
                'notes' => $packagingItem->notes,
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function deletePackagingItem(User $user, int $packagingItemId): array
    {
        UserPackagingItem::query()
            ->where('user_id', $user->id)
            ->whereKey($packagingItemId)
            ->delete();

        return $this->packagingCatalogPayload($user);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function packagingCatalogPayload(User $user): array
    {
        return UserPackagingItem::query()
            ->where('user_id', $user->id)
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->map(fn (UserPackagingItem $item): array => [
                'id' => $item->id,
                'name' => $item->name,
                'unit_cost' => (float) $item->unit_cost,
                'currency' => $item->currency,
                'notes' => $item->notes,
            ])
            ->values()
            ->all();
    }

    public function ensureCosting(RecipeVersion $recipeVersion, User $user): RecipeVersionCosting
    {
        return DB::transaction(function () use ($recipeVersion, $user): RecipeVersionCosting {
            $costing = RecipeVersionCosting::query()->firstOrCreate(
                [
                    'recipe_version_id' => $recipeVersion->id,
                    'user_id' => $user->id,
                ],
                [
                    'oil_weight_for_costing' => $recipeVersion->batch_size,
                    'oil_unit_for_costing' => $recipeVersion->batch_unit,
                    'currency' => 'EUR',
                ],
            );

            $this->syncFormulaItems($costing);

            return $costing->fresh(['items', 'packagingItems']) ?? $costing->load(['items', 'packagingItems']);
        });
    }

    private function syncFormulaItems(RecipeVersionCosting $costing): void
    {
        $recipeVersion = RecipeVersion::withoutGlobalScopes()
            ->with([
                'phases' => fn ($query) => $query->withoutGlobalScopes()->orderBy('sort_order'),
                'phases.items' => fn ($query) => $query->withoutGlobalScopes()->orderBy('position'),
            ])
            ->findOrFail($costing->recipe_version_id);

        $desiredRows = collect($recipeVersion->phases)
            ->sortBy('sort_order')
            ->flatMap(fn (RecipePhase $phase): Collection => $phase->items
                ->sortBy('position')
                ->map(fn ($item): array => [
                    'ingredient_id' => (int) $item->ingredient_id,
                    'phase_key' => $phase->slug,
                    'position' => (int) $item->position,
                ]))
            ->values();

        $existingRows = $costing->items()->get()->keyBy(fn (RecipeVersionCostingItem $item): string => $this->costingKey(
            (int) $item->ingredient_id,
            $item->phase_key,
            (int) $item->position,
        ));

        $defaultPricesByIngredient = UserIngredientPrice::query()
            ->where('user_id', $costing->user_id)
            ->whereIn('ingredient_id', $desiredRows->pluck('ingredient_id')->all())
            ->get()
            ->keyBy('ingredient_id');

        $costing->items()->delete();

        $desiredRows->each(function (array $row) use ($costing, $defaultPricesByIngredient, $existingRows): void {
            $rowKey = $this->costingKey($row['ingredient_id'], $row['phase_key'], $row['position']);
            $existingRow = $existingRows->get($rowKey);
            $defaultPrice = $defaultPricesByIngredient->get($row['ingredient_id']);

            $costing->items()->create([
                'ingredient_id' => $row['ingredient_id'],
                'phase_key' => $row['phase_key'],
                'position' => $row['position'],
                'price_per_kg' => $existingRow?->price_per_kg ?? $defaultPrice?->price_per_kg,
            ]);
        });
    }

    private function applyItemPrices(RecipeVersionCosting $costing, User $user, mixed $rawItems): void
    {
        $submittedItems = collect(is_array($rawItems) ? $rawItems : [])
            ->filter(fn (mixed $row): bool => is_array($row) && isset($row['ingredient_id'], $row['phase_key'], $row['position']))
            ->keyBy(fn (array $row): string => $this->costingKey(
                (int) $row['ingredient_id'],
                (string) $row['phase_key'],
                (int) $row['position'],
            ));

        $costing->items()->get()->each(function (RecipeVersionCostingItem $item) use ($costing, $submittedItems, $user): void {
            $submittedRow = $submittedItems->get($this->costingKey(
                (int) $item->ingredient_id,
                $item->phase_key,
                (int) $item->position,
            ));

            if (! is_array($submittedRow)) {
                return;
            }

            $item->price_per_kg = $this->nullableFloat($submittedRow['price_per_kg'] ?? null);
            $item->save();

            if ($item->price_per_kg !== null) {
                UserIngredientPrice::query()->updateOrCreate(
                    [
                        'user_id' => $user->id,
                        'ingredient_id' => $item->ingredient_id,
                    ],
                    [
                        'price_per_kg' => $item->price_per_kg,
                        'currency' => $costing->currency,
                        'last_used_at' => now(),
                    ],
                );
            }
        });
    }

    private function replacePackagingItems(RecipeVersionCosting $costing, mixed $rawItems): void
    {
        $costing->packagingItems()->delete();

        collect(is_array($rawItems) ? $rawItems : [])
            ->filter(fn (mixed $row): bool => is_array($row) && filled($row['name'] ?? null))
            ->each(function (array $row) use ($costing): void {
                $costing->packagingItems()->create([
                    'user_packaging_item_id' => isset($row['user_packaging_item_id']) && is_numeric($row['user_packaging_item_id'])
                        ? (int) $row['user_packaging_item_id']
                        : null,
                    'name' => trim((string) $row['name']),
                    'unit_cost' => (float) ($row['unit_cost'] ?? 0),
                    'quantity' => (float) ($row['components_per_unit'] ?? $row['quantity'] ?? 0),
                ]);
            });
    }

    private function costingKey(int $ingredientId, string $phaseKey, int $position): string
    {
        return implode(':', [$ingredientId, $phaseKey, $position]);
    }

    private function nullableFloat(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        return round((float) $value, 4);
    }

    private function nullableInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $normalized = (int) $value;

        return $normalized > 0 ? $normalized : null;
    }

    private function normalizeCurrency(mixed $value): string
    {
        $currency = strtoupper(trim((string) $value));

        return strlen($currency) === 3 ? $currency : 'EUR';
    }

    private function normalizeOilUnit(mixed $value): string
    {
        return in_array($value, ['g', 'kg', 'oz', 'lb'], true)
            ? $value
            : 'g';
    }
}
