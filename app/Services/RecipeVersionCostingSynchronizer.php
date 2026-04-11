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
 *
 * This service is the core of the costing feature. It handles:
 * - Loading the costing payload for the frontend
 * - Saving costing changes (settings, prices, packaging)
 * - Copying costing forward when versions are published
 * - Reconciling costing items against the current formula structure
 *
 * Key invariant: once a user sets a price in a formula's costing, that price
 * stays stable even if their global default price for the same ingredient changes.
 */
class RecipeVersionCostingSynchronizer
{
    /**
     * Build the full costing payload for the frontend.
     *
     * Returns settings, ingredient prices, packaging rows, and the user's packaging
     * catalog. The packaging catalog is always available (even before a first draft
     * save) so the user can browse and create items early.
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
     * Persist costing changes: settings, ingredient prices, and packaging rows.
     *
     * Runs inside a transaction so settings, item prices, and packaging are all
     * saved atomically. Returns the full costing payload after save so the
     * frontend can reconcile its local state.
     *
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

    /**
     * Copy costing from a source version to a target version.
     *
     * Used when publishing a draft (the published version and the new draft both
     * get a copy). Settings, packaging rows, and item prices (matched by
     * ingredient_id + phase_key + position) are all forwarded so the user does
     * not lose their costing work during the version lifecycle.
     */
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
     * Create or update a reusable packaging catalog item for the user.
     *
     * If payload contains an 'id', the existing item is updated. Otherwise a new
     * one is created. Returns the updated catalog and the saved item payload.
     *
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
            'currency' => $this->normalizeCurrency($payload['currency'] ?? $user->defaultCurrency()),
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
     * Return the user's full packaging catalog, ordered by name then id.
     *
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

    /**
     * Ensure a costing record exists for this (recipe_version, user) pair.
     *
     * Creates one if missing (defaulting oil weight and unit from the recipe version),
     * then syncs costing items against the current formula structure. Returns the
     * costing with items and packagingItems eager-loaded.
     */
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
                    'currency' => $user->defaultCurrency(),
                ],
            );

            $this->syncFormulaItems($costing);

            return $costing->fresh(['items', 'packagingItems']) ?? $costing->load(['items', 'packagingItems']);
        });
    }

    /**
     * Rebuild costing items from the current formula structure.
     *
     * Reconciliation rules:
     * - New formula rows → create costing row, prefilled from user_ingredient_prices
     * - Deleted formula rows → their costing rows are removed
     * - Unchanged rows → preserve the saved costing price
     * - Reordered rows → preserve price, update position
     *
     * This runs inside ensureCosting() and save(), so the costing items always
     * reflect the current formula state.
     */
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

    /**
     * Apply submitted item prices to costing rows and upsert user ingredient price memory.
     *
     * For each submitted price, the costing item is updated. If the price is non-null,
     * the user's global price memory (user_ingredient_prices) is also updated so the
     * price can be prefilled in future recipes containing the same ingredient.
     */
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

    /**
     * Replace all packaging rows on the costing with the submitted set.
     *
     * Uses a full replace strategy (delete all, then recreate) to keep the
     * persistence logic simple. Accepts both 'components_per_unit' and 'quantity'
     * keys for backward compatibility with older frontend payloads.
     */
    private function replacePackagingItems(RecipeVersionCosting $costing, mixed $rawItems): void
    {
        $submittedItems = collect(is_array($rawItems) ? $rawItems : [])
            ->filter(fn (mixed $row): bool => is_array($row) && filled($row['name'] ?? null))
            ->values();

        $linkedPackagingItems = UserPackagingItem::query()
            ->where('user_id', $costing->user_id)
            ->whereIn('id', $submittedItems
                ->pluck('user_packaging_item_id')
                ->filter(fn (mixed $id): bool => is_numeric($id))
                ->map(fn (mixed $id): int => (int) $id)
                ->all())
            ->get()
            ->keyBy('id');

        $costing->packagingItems()->delete();

        $submittedItems
            ->each(function (array $row) use ($costing, $linkedPackagingItems): void {
                $linkedPackagingItemId = isset($row['user_packaging_item_id']) && is_numeric($row['user_packaging_item_id'])
                    ? (int) $row['user_packaging_item_id']
                    : null;
                $unitCost = (float) ($row['unit_cost'] ?? 0);

                $costing->packagingItems()->create([
                    'user_packaging_item_id' => $linkedPackagingItemId,
                    'name' => trim((string) $row['name']),
                    'unit_cost' => $unitCost,
                    'quantity' => (float) ($row['components_per_unit'] ?? $row['quantity'] ?? 0),
                ]);

                $linkedPackagingItem = $linkedPackagingItems->get($linkedPackagingItemId);

                if (! $linkedPackagingItem instanceof UserPackagingItem) {
                    return;
                }

                $linkedPackagingItem->forceFill([
                    'unit_cost' => $unitCost,
                    'currency' => $costing->currency,
                ])->save();
            });
    }

    /** Build a composite key for matching costing rows to formula rows. */
    private function costingKey(int $ingredientId, string $phaseKey, int $position): string
    {
        return implode(':', [$ingredientId, $phaseKey, $position]);
    }

    /** Cast a value to a rounded float, or null if non-numeric. */
    private function nullableFloat(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        return round((float) $value, 4);
    }

    /** Cast a value to a positive integer, or null if non-numeric or zero. */
    private function nullableInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $normalized = (int) $value;

        return $normalized > 0 ? $normalized : null;
    }

    /** Normalize a currency string to a valid ISO 4217 code, defaulting to EUR. */
    private function normalizeCurrency(mixed $value): string
    {
        $currency = strtoupper(trim((string) $value));
        $validCurrencies = array_keys(config('currencies', []));

        return in_array($currency, $validCurrencies, true) ? $currency : 'EUR';
    }

    /** Normalize an oil unit to one of the allowed values, defaulting to grams. */
    private function normalizeOilUnit(mixed $value): string
    {
        return in_array($value, ['g', 'kg', 'oz', 'lb'], true)
            ? $value
            : 'g';
    }
}
