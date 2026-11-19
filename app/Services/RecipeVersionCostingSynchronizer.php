<?php

namespace App\Services;

use App\Enums\IngredientCategory;
use App\Enums\IngredientSubcategory;
use App\Enums\MassUnit;
use App\Enums\MaterialPriceSource;
use App\Enums\PackagingCategory;
use App\Models\CurrentMaterialPrice;
use App\Models\Ingredient;
use App\Models\PackagingItem;
use App\Models\ProductFamily;
use App\Models\ProductSize;
use App\Models\Recipe;
use App\Models\RecipePhase;
use App\Models\RecipeVersion;
use App\Models\RecipeVersionCosting;
use App\Models\RecipeVersionCostingItem;
use App\Models\RecipeVersionCostingPackagingItem;
use App\Models\RecipeVersionPackagingItem;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

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
    /** Synthetic phase key that prices the calculated soap alkali (NaOH/KOH). */
    private const ALKALI_PHASE_KEY = 'lye_alkali';

    /** Synthetic phase key that prices the plain-water share of the dilution liquid. */
    private const IMPLICIT_WATER_PHASE_KEY = 'implicit_water';

    public function __construct(
        private readonly CurrentMaterialPriceService $currentMaterialPriceService,
        private readonly PackagingItemAuthoringService $packagingItemAuthoringService,
        private readonly MassConverter $massConverter,
        private readonly CanonicalSoapAlkaliResolver $alkaliResolver,
        private readonly ProductSizeSynchronizer $productSizeSynchronizer,
        private readonly ProductSizeResolver $productSizeResolver,
    ) {}

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
        if ($recipe instanceof Recipe) {
            Gate::forUser($user)->authorize('view', $recipe);
        }

        $packagingCatalog = $user instanceof User
            ? $this->packagingCatalogPayload($user)
            : [];

        $currentVersion = $recipe instanceof Recipe
            ? RecipeVersion::withoutGlobalScopes()
                ->where('recipe_id', $recipe->id)
                ->where('is_current', true)
                ->first()
            : null;

        if (! $currentVersion instanceof RecipeVersion || ! $user instanceof User) {
            return [
                'settings' => null,
                'item_prices' => [],
                'alkali_ingredients' => [],
                'water_ingredient' => null,
                'packaging_items' => [],
                'packaging_catalog' => $packagingCatalog,
            ];
        }

        $costing = RecipeVersionCosting::query()
            ->with(['items', 'packagingItems.packagingItem', 'packagingItems.productSize', 'sizeYields'])
            ->where('recipe_version_id', $currentVersion->id)
            ->first();

        if (! $costing instanceof RecipeVersionCosting) {
            $costing = $this->projectCosting($currentVersion);
        }

        $workspace = Workspace::withoutGlobalScopes()->findOrFail($currentVersion->workspace_id);
        $sizes = $this->productSizeResolver->forVersion($currentVersion, costing: true);

        return [
            'settings' => [
                'id' => $costing->id,
                'oilWeightForCosting' => $this->costingDisplayMass($costing),
                'oilUnitForCosting' => $costing->oil_unit_for_costing,
                'unitsProduced' => $costing->units_produced,
                'unitsProducedBySize' => $this->sizeYieldValues($costing, $sizes->first()),
                'sizeKeys' => $sizes->pluck('public_id')->all(),
                'currency' => $workspace->default_currency,
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
            'alkali_ingredients' => $this->alkaliIngredientsPayload($currentVersion),
            'water_ingredient' => $this->waterIngredientPayload($currentVersion),
            'packaging_items' => $costing->packagingItems
                ->map(fn (RecipeVersionCostingPackagingItem $item): array => [
                    'id' => $item->id,
                    'product_size_key' => $item->productSize?->public_id,
                    'packaging_item_id' => $item->packaging_item_id,
                    'name' => $item->name,
                    'material_code' => $item->packagingItem?->material_code,
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
        Gate::forUser($user)->authorize('update', $recipeVersion);

        $workspace = Workspace::withoutGlobalScopes()->findOrFail($recipeVersion->workspace_id);

        return DB::transaction(function () use ($payload, $recipeVersion, $user, $workspace): array {
            $costing = $this->ensureCosting($recipeVersion, $user);
            $oilUnit = $this->normalizeOilUnit($payload['oil_unit_for_costing'] ?? $costing->oil_unit_for_costing);
            $oilWeight = $this->nullableFloat($payload['oil_weight_for_costing'] ?? null);

            $costing->fill([
                'updated_by_user_id' => $user->id,
                'oil_weight_for_costing' => $oilWeight,
                'oil_unit_for_costing' => $oilUnit,
                'oil_mass_grams_for_costing' => $oilWeight === null
                    ? null
                    : $this->massConverter->toGrams($payload['oil_weight_for_costing'], $oilUnit),
                'units_produced' => array_key_exists('units_produced_by_size', $payload)
                    ? $costing->units_produced
                    : $this->nullableInt($payload['units_produced'] ?? null),
                'currency' => $workspace->default_currency,
            ]);
            $costing->save();
            if (array_key_exists('units_produced_by_size', $payload)) {
                $this->saveSizeYields($costing, $recipeVersion, $payload['units_produced_by_size']);
            } elseif (array_key_exists('units_produced', $payload)) {
                $defaultSize = $this->productSizeResolver->forVersion($recipeVersion, costing: true)->first();
                if ($defaultSize !== null) {
                    $costing->sizeYields()->syncWithoutDetaching([$defaultSize->id => ['units_produced' => $costing->units_produced]]);
                }
            }

            $this->syncFormulaItems($costing);
            $this->applyItemPrices($costing, $user, $payload['items'] ?? []);
            $this->replacePackagingItems($costing, $user, $payload['packaging_items'] ?? []);

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
        Gate::forUser($user)->authorize('update', $targetVersion);

        if (! $sourceVersion instanceof RecipeVersion) {
            return;
        }

        Gate::forUser($user)->authorize('view', $sourceVersion);

        $sourceCosting = RecipeVersionCosting::query()
            ->with(['items', 'packagingItems', 'sizeYields'])
            ->where('recipe_version_id', $sourceVersion->id)
            ->first();

        if (! $sourceCosting instanceof RecipeVersionCosting) {
            return;
        }

        $targetWorkspace = Workspace::withoutGlobalScopes()->findOrFail($targetVersion->workspace_id);

        $targetSizeIds = $this->targetSizeIds($sourceCosting, $sourceVersion, $targetVersion);

        DB::transaction(function () use ($sourceCosting, $targetVersion, $user, $targetWorkspace, $targetSizeIds): void {
            $targetCosting = RecipeVersionCosting::query()->firstOrNew([
                'recipe_version_id' => $targetVersion->id,
            ], [
                'user_id' => $user->id,
            ]);

            $targetCosting->fill([
                'updated_by_user_id' => $user->id,
                'oil_weight_for_costing' => $sourceCosting->oil_weight_for_costing,
                'oil_unit_for_costing' => $sourceCosting->oil_unit_for_costing,
                'oil_mass_grams_for_costing' => $sourceCosting->oil_mass_grams_for_costing,
                'units_produced' => $sourceCosting->units_produced,
                'currency' => $targetWorkspace->default_currency,
            ]);
            $targetCosting->save();
            $targetCosting->sizeYields()->sync(
                $sourceCosting->sizeYields->filter(fn (ProductSize $size): bool => isset($targetSizeIds[$size->id]))
                    ->mapWithKeys(fn (ProductSize $size): array => [
                        $targetSizeIds[$size->id] => ['units_produced' => $size->pivot->units_produced],
                    ])->all(),
            );

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

            $sourceCosting->packagingItems->each(function (RecipeVersionCostingPackagingItem $item) use ($targetCosting, $targetSizeIds): void {
                $targetCosting->packagingItems()->create([
                    'product_size_id' => $item->product_size_id === null ? null : ($targetSizeIds[$item->product_size_id] ?? null),
                    'packaging_item_id' => $item->packaging_item_id,
                    'name' => $item->name,
                    'unit_cost' => $item->unit_cost,
                    'quantity' => $item->quantity,
                ]);
            });

            $this->addMissingPlanPackagingRows($targetCosting);
        });
    }

    /**
     * Map each priced source size to the target Product's matching size.
     *
     * Versions of one Product share its sizes. A duplicated Product has its
     * own sizes, so prices move to the copies; a price whose size has no copy
     * is kept unsized and still matches by packaging row.
     *
     * @return array<int, int>
     */
    private function targetSizeIds(RecipeVersionCosting $sourceCosting, RecipeVersion $sourceVersion, RecipeVersion $targetVersion): array
    {
        $sourceSizeIds = $sourceCosting->packagingItems->pluck('product_size_id')
            ->concat($sourceCosting->sizeYields->modelKeys())->filter()->unique()->values()->all();

        if ((int) $sourceVersion->recipe_id === (int) $targetVersion->recipe_id) {
            return array_combine($sourceSizeIds, $sourceSizeIds);
        }

        return $this->productSizeSynchronizer->copiedSizeIds((int) $targetVersion->recipe_id, $sourceSizeIds);
    }

    /** @return array<string, int|null> */
    public function sizeYieldValues(RecipeVersionCosting $costing, ?ProductSize $defaultSize): array
    {
        $costing->loadMissing('sizeYields');
        if ($costing->sizeYields->isEmpty() && $defaultSize !== null && $costing->units_produced !== null) {
            return [$defaultSize->public_id => $costing->units_produced];
        }

        return $costing->sizeYields->mapWithKeys(fn (ProductSize $size): array => [
            $size->public_id => $size->pivot->units_produced === null ? null : (int) $size->pivot->units_produced,
        ])->all();
    }

    private function saveSizeYields(RecipeVersionCosting $costing, RecipeVersion $version, mixed $submitted): void
    {
        $sizes = $this->productSizeResolver->forVersion($version, costing: true);
        $sizesByKey = $sizes->keyBy('public_id');
        $values = $this->sizeYieldValues($costing, $sizes->first());
        if (! is_array($submitted) || count($submitted) > RecipeWorkbenchPayloadNormalizer::MaxProductSizes) {
            throw ValidationException::withMessages(['units_produced_by_size' => __('workbench.costing.settings.size_yield_invalid')]);
        }

        foreach ($submitted as $key => $value) {
            $units = $value === null || $value === '' ? null : filter_var($value, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1, 'max_range' => 1_000_000],
            ]);
            if (is_bool($value) || $units === false || ! $sizesByKey->has($key)) {
                throw ValidationException::withMessages(['units_produced_by_size' => __('workbench.costing.settings.size_yield_invalid')]);
            }
            $values[$key] = $units;
        }

        $costing->sizeYields()->syncWithoutDetaching(collect($values)
            ->filter(fn (mixed $units, string $key): bool => $sizesByKey->has($key))
            ->mapWithKeys(fn (?int $units, string $key): array => [
                $sizesByKey->get($key)->id => ['units_produced' => $units],
            ])->all());
    }

    /**
     * Add costing rows for target-version plan packaging rows missing from the copied set.
     *
     * Copying forwards the source costing as-is, which can lag behind plan changes made
     * after the last reconciliation. Matching by key keeps copied prices while newly
     * planned rows enter with their catalog cost.
     */
    private function addMissingPlanPackagingRows(RecipeVersionCosting $targetCosting): void
    {
        $planItems = RecipeVersion::withoutGlobalScopes()
            ->with(['packagingItems.packagingItem'])
            ->findOrFail($targetCosting->recipe_version_id)
            ->packagingItems
            ->sortBy('position');

        if ($planItems->isEmpty()) {
            return;
        }

        $existingRowsByKey = $this->packagingCostingRowsByKey($targetCosting->packagingItems()->get());
        $occurrences = [];

        $planItems->each(function (RecipeVersionPackagingItem $item) use ($targetCosting, $existingRowsByKey, &$occurrences): void {
            $existingRow = $this->matchingPackagingCostingRow($existingRowsByKey, $item, $occurrences);

            if ($existingRow instanceof RecipeVersionCostingPackagingItem) {
                return;
            }

            $catalogItem = $item->packagingItem;

            $targetCosting->packagingItems()->create([
                'product_size_id' => $item->product_size_id,
                'packaging_item_id' => $item->packaging_item_id,
                'name' => $item->name,
                'unit_cost' => $catalogItem?->unit_cost ?? 0,
                'quantity' => $item->components_per_unit,
            ]);
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

        if (isset($payload['id'])) {
            if (! is_numeric($payload['id'])) {
                throw ValidationException::withMessages([
                    'packaging_item' => 'Only your own packaging items can be edited from the costing tab.',
                ]);
            }

            $packagingItem = PackagingItem::query()
                ->where('workspace_id', $user->company()?->id)
                ->find((int) $payload['id']);

            if (! $packagingItem instanceof PackagingItem) {
                throw ValidationException::withMessages([
                    'packaging_item' => 'Only your own packaging items can be edited from the costing tab.',
                ]);
            }
            $packagingItem = $this->packagingItemAuthoringService->update($packagingItem, [
                'name' => $name,
                'material_code' => array_key_exists('material_code', $payload)
                    ? $payload['material_code']
                    : $packagingItem->material_code,
                'category' => $payload['category'] ?? $packagingItem->category->value,
                'unit_cost' => $payload['unit_cost'] ?? 0,
                'notes' => $payload['notes'] ?? null,
            ], $user);
        } else {
            $packagingItem = $this->packagingItemAuthoringService->create([
                'name' => $name,
                'material_code' => $payload['material_code'] ?? null,
                'category' => $payload['category'] ?? PackagingCategory::Other->value,
                'unit_cost' => $payload['unit_cost'] ?? 0,
                'notes' => $payload['notes'] ?? null,
            ], $user);
        }

        return [
            'packaging_catalog' => $this->packagingCatalogPayload($user),
            'packaging_item' => [
                'id' => $packagingItem->id,
                'name' => $packagingItem->name,
                'material_code' => $packagingItem->material_code,
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
        $currency = $user->defaultCurrency();

        return PackagingItem::query()
            ->where('workspace_id', $user->company()?->id)
            ->with('currentPrice')
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->map(fn (PackagingItem $item): array => [
                'id' => $item->id,
                'name' => $item->name,
                'material_code' => $item->material_code,
                'unit_cost' => (float) $item->unit_cost,
                'currency' => $currency,
                'notes' => $item->notes,
            ])
            ->values()
            ->all();
    }

    /** Build current costing defaults and rows without persisting them. */
    public function projectCosting(RecipeVersion $recipeVersion, ?RecipeVersionCosting $existingCosting = null): RecipeVersionCosting
    {
        $recipeVersion = clone $recipeVersion;
        $recipeVersion->load([
            'phases' => fn ($query) => $query->withoutGlobalScopes()->orderBy('sort_order'),
            'phases.items' => fn ($query) => $query->withoutGlobalScopes()->with('ingredient')->orderBy('position'),
            'packagingItems.packagingItem',
        ]);
        $existingCosting?->loadMissing(['items', 'packagingItems.packagingItem']);
        $unit = $this->normalizeOilUnit($recipeVersion->batch_unit);
        $canonicalMass = $this->formulaCanonicalMass($recipeVersion, $unit);
        $workspace = Workspace::withoutGlobalScopes()->findOrFail($recipeVersion->workspace_id);
        $costing = $existingCosting === null
            ? new RecipeVersionCosting([
                'recipe_version_id' => $recipeVersion->id,
                'oil_weight_for_costing' => $canonicalMass === null
                    ? $recipeVersion->batch_size
                    : $this->massConverter->fromGrams($canonicalMass, $unit),
                'oil_unit_for_costing' => $unit,
                'oil_mass_grams_for_costing' => $canonicalMass,
                'units_produced' => null,
                'currency' => $workspace->default_currency,
            ])
            : clone $existingCosting;

        $costing->setRelation('recipeVersion', $recipeVersion);
        $projectedItems = $this->projectFormulaItems($recipeVersion, $existingCosting);
        $desiredKeys = $projectedItems->mapWithKeys(fn (RecipeVersionCostingItem $item): array => [
            $this->costingKey((int) $item->ingredient_id, $item->phase_key, (int) $item->position) => true,
        ]);
        $savedOnlyItems = ($existingCosting?->items ?? collect())
            ->reject(fn (RecipeVersionCostingItem $item): bool => $desiredKeys->has($this->costingKey(
                (int) $item->ingredient_id,
                $item->phase_key,
                (int) $item->position,
            )))
            ->map(fn (RecipeVersionCostingItem $item): RecipeVersionCostingItem => clone $item);
        $costing->setRelation('items', $projectedItems->concat($savedOnlyItems)->values());
        $costing->setRelation('packagingItems', $this->projectPackagingItems($recipeVersion, $existingCosting));

        return $costing;
    }

    /**
     * Ensure a costing record exists for this recipe version.
     *
     * Creates one if missing (defaulting oil weight and unit from the recipe version),
     * then syncs costing items against the current formula structure. Returns the
     * costing with items and packagingItems eager-loaded.
     */
    public function ensureCosting(RecipeVersion $recipeVersion, User $user): RecipeVersionCosting
    {
        Gate::forUser($user)->authorize('update', $recipeVersion);

        $workspace = Workspace::withoutGlobalScopes()->findOrFail($recipeVersion->workspace_id);

        return DB::transaction(function () use ($recipeVersion, $user, $workspace): RecipeVersionCosting {
            $oilUnit = $this->normalizeOilUnit($recipeVersion->batch_unit);
            $canonicalMass = $this->formulaCanonicalMass($recipeVersion, $oilUnit);

            $costing = RecipeVersionCosting::query()->firstOrCreate(
                [
                    'recipe_version_id' => $recipeVersion->id,
                ],
                [
                    'user_id' => $user->id,
                    'oil_weight_for_costing' => $canonicalMass === null
                        ? $recipeVersion->batch_size
                        : $this->massConverter->fromGrams($canonicalMass, $oilUnit),
                    'oil_unit_for_costing' => $oilUnit,
                    'oil_mass_grams_for_costing' => $canonicalMass,
                    'currency' => $workspace->default_currency,
                ],
            );

            $this->syncFormulaItems($costing);
            $this->syncPackagingItems($costing);

            return $costing->fresh(['items', 'packagingItems.packagingItem'])
                ?? $costing->load(['items', 'packagingItems.packagingItem']);
        });
    }

    /**
     * Reconcile formula and packaging rows only when this version already has costing.
     */
    public function reconcileExistingCosting(RecipeVersion $recipeVersion, User $user): void
    {
        Gate::forUser($user)->authorize('update', $recipeVersion);

        $costing = RecipeVersionCosting::query()
            ->where('recipe_version_id', $recipeVersion->id)
            ->first();

        if (! $costing instanceof RecipeVersionCosting) {
            return;
        }

        DB::transaction(function () use ($costing): void {
            $this->syncFormulaItems($costing);
            $this->syncPackagingItems($costing);
        });
    }

    /** Reconcile formula rows without changing separately managed packaging costs. */
    public function reconcileExistingFormulaCosting(RecipeVersion $recipeVersion, User $user): void
    {
        Gate::forUser($user)->authorize('update', $recipeVersion);

        $costing = RecipeVersionCosting::query()
            ->where('recipe_version_id', $recipeVersion->id)
            ->first();

        if (! $costing instanceof RecipeVersionCosting) {
            return;
        }

        DB::transaction(function () use ($costing): void {
            $this->syncFormulaItems($costing);
        });
    }

    /**
     * Rebuild costing items from the current formula structure.
     *
     * Reconciliation rules:
     * - New formula rows → create costing row, prefilled from the workspace current price
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

        $rows = $this->projectFormulaItems($recipeVersion, (clone $costing)->load('items'));
        $costing->items()->delete();
        $rows->each(fn (RecipeVersionCostingItem $item) => $costing->items()->create($item->getAttributes()));
    }

    /** @return Collection<int, RecipeVersionCostingItem> */
    private function projectFormulaItems(RecipeVersion $recipeVersion, ?RecipeVersionCosting $costing): Collection
    {
        $desiredRows = collect($recipeVersion->phases)
            ->sortBy('sort_order')
            ->flatMap(fn (RecipePhase $phase): Collection => $phase->items
                ->sortBy('position')
                ->map(fn ($item): array => [
                    'ingredient_id' => (int) $item->ingredient_id,
                    'phase_key' => $phase->slug,
                    'position' => (int) $item->position,
                ]))
            ->values()
            ->merge($this->alkaliDesiredRows($recipeVersion))
            ->merge($this->implicitWaterDesiredRows($recipeVersion))
            ->values();

        $existingRows = ($costing?->items ?? collect())->keyBy(fn (RecipeVersionCostingItem $item): string => $this->costingKey(
            (int) $item->ingredient_id,
            $item->phase_key,
            (int) $item->position,
        ));

        $defaultPricesByIngredient = CurrentMaterialPrice::query()
            ->where('workspace_id', $recipeVersion->workspace_id)
            ->whereNotNull('ingredient_id')
            ->whereIn('ingredient_id', $desiredRows->pluck('ingredient_id')->all())
            ->get()
            ->keyBy('ingredient_id');

        return $desiredRows->map(function (array $row) use ($defaultPricesByIngredient, $existingRows): RecipeVersionCostingItem {
            $rowKey = $this->costingKey($row['ingredient_id'], $row['phase_key'], $row['position']);
            $existingRow = $existingRows->get($rowKey);
            $defaultPrice = $defaultPricesByIngredient->get($row['ingredient_id']);

            return new RecipeVersionCostingItem([
                'ingredient_id' => $row['ingredient_id'],
                'phase_key' => $row['phase_key'],
                'position' => $row['position'],
                'price_per_kg' => $existingRow instanceof RecipeVersionCostingItem
                    ? $existingRow->price_per_kg
                    : ($defaultPrice?->price_per_canonical_unit === null
                        ? null
                        : bcmul($defaultPrice->price_per_canonical_unit, '1000', 12)),
            ]);
        });
    }

    /**
     * Costing identity for the calculated soap alkali (NaOH/KOH), exposed to the frontend.
     *
     * Alkali is never a formula row — it is derived from SAP values and lye settings —
     * so the payload carries the resolved canonical ingredient records the costing
     * table prices. Both identities resolve because the user can change lye type
     * without reloading.
     *
     * @return array<string, array<string, mixed>>
     */
    private function alkaliIngredientsPayload(RecipeVersion $recipeVersion): array
    {
        if (! $this->isSoapVersion($recipeVersion)) {
            return [];
        }

        return $this->alkaliResolver->resolveMany(['naoh', 'koh'])
            ->map(fn (Ingredient $ingredient): array => [
                'ingredient_id' => $ingredient->id,
                'name' => $ingredient->localizedDisplayName(),
                'default_price_per_kg' => $this->derivedIngredientDefaultPricePerKg($recipeVersion, $ingredient),
            ])
            ->all();
    }

    /**
     * Desired costing rows for the calculated alkali, keyed like formula rows.
     *
     * NaOH comes first so dual-lye positions stay stable: naoh → position 1,
     * koh → position 2. The synthetic ALKALI_PHASE_KEY keeps these rows out of
     * every authored phase while reusing the standard reconciliation rules.
     *
     * @return Collection<int, array{ingredient_id: int, phase_key: string, position: int}>
     */
    private function alkaliDesiredRows(RecipeVersion $recipeVersion): Collection
    {
        if (! $this->isSoapVersion($recipeVersion)) {
            return collect();
        }

        $lyeType = $recipeVersion->calculation_context['lye_type'] ?? 'naoh';
        $lyeTypes = match ($lyeType) {
            'dual' => ['naoh', 'koh'],
            'koh' => ['koh'],
            default => ['naoh'],
        };

        return $this->alkaliResolver->resolveMany($lyeTypes)
            ->values()
            ->map(fn (Ingredient $ingredient, int $index): array => [
                'ingredient_id' => (int) $ingredient->id,
                'phase_key' => self::ALKALI_PHASE_KEY,
                'position' => $index + 1,
            ]);
    }

    /**
     * Costing identity for the plain-water share of the dilution liquid.
     *
     * Water not covered by added liquids is implicit (own tap water by default),
     * but it still deserves a costing row: some users buy their water, and even
     * free water makes the batch economics visible at a zero price.
     */
    private function waterIngredientPayload(RecipeVersion $recipeVersion): ?array
    {
        if (! $this->isSoapVersion($recipeVersion)) {
            return null;
        }

        $water = $this->soapWaterIngredient();

        if (! $water instanceof Ingredient) {
            return null;
        }

        return [
            'ingredient_id' => $water->id,
            'name' => $water->localizedDisplayName(),
            'default_price_per_kg' => $this->derivedIngredientDefaultPricePerKg($recipeVersion, $water),
        ];
    }

    private function soapWaterIngredient(): ?Ingredient
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

    /**
     * Desired costing row for the implicit plain-water share of the dilution liquid.
     *
     * Present only when the added lye-liquid shares leave a water remainder
     * (100% − sum of explicit shares > 0). A single fixed position keeps the
     * signature stable for price persistence.
     */
    private function implicitWaterDesiredRows(RecipeVersion $recipeVersion): Collection
    {
        if (! $this->isSoapVersion($recipeVersion)) {
            return collect();
        }

        $explicitLiquidShare = (float) $recipeVersion->phases
            ->firstWhere('slug', 'lye_water')
            ?->items
            ->sum(fn ($item): float => max(0.0, (float) $item->percentage));
        $dilutesWithWater = $this->productFamilyFor($recipeVersion)?->usesLiquidSoapPasteBench() ?? false;

        // A liquid soap is diluted with plain water whatever its alkali solution uses.
        if (! $dilutesWithWater && 100.0 - min(100.0, $explicitLiquidShare) <= 0.0) {
            return collect();
        }

        $water = $this->soapWaterIngredient();

        if (! $water instanceof Ingredient) {
            return collect();
        }

        return collect([[
            'ingredient_id' => (int) $water->id,
            'phase_key' => self::IMPLICIT_WATER_PHASE_KEY,
            'position' => 1,
        ]]);
    }

    private function isSoapVersion(RecipeVersion $recipeVersion): bool
    {
        return $this->productFamilyFor($recipeVersion)?->usesSoapCalculation() ?? false;
    }

    private function productFamilyFor(RecipeVersion $recipeVersion): ?ProductFamily
    {
        $recipe = Recipe::query()->withoutGlobalScopes()->find($recipeVersion->recipe_id);

        return $recipe?->productFamily()->withoutGlobalScopes()->first();
    }

    private function derivedIngredientDefaultPricePerKg(RecipeVersion $recipeVersion, Ingredient $ingredient): ?float
    {
        $defaultPrice = CurrentMaterialPrice::query()
            ->where('workspace_id', $recipeVersion->workspace_id)
            ->whereNotNull('ingredient_id')
            ->where('ingredient_id', $ingredient->id)
            ->first();

        return $defaultPrice?->price_per_canonical_unit === null
            ? null
            : (float) bcmul($defaultPrice->price_per_canonical_unit, '1000', 12);
    }

    /**
     * Rebuild costing packaging rows from the recipe-version packaging plan.
     *
     * Packaging plan rows are recipe structure. Costing rows keep the price used
     * for this formula, preserving existing overrides when the plan row
     * is still present.
     */
    private function syncPackagingItems(RecipeVersionCosting $costing): void
    {
        $recipeVersion = RecipeVersion::withoutGlobalScopes()
            ->with(['packagingItems.packagingItem'])
            ->findOrFail($costing->recipe_version_id);

        $rows = $this->projectPackagingItems($recipeVersion, (clone $costing)->load('packagingItems'));
        $costing->packagingItems()->delete();
        $rows->each(fn (RecipeVersionCostingPackagingItem $item) => $costing->packagingItems()->create([
            ...$item->getAttributes(),
            'unit_cost' => $item->unit_cost ?? 0,
        ]));
    }

    /** @return Collection<int, RecipeVersionCostingPackagingItem> */
    private function projectPackagingItems(RecipeVersion $recipeVersion, ?RecipeVersionCosting $costing): Collection
    {
        $existingRowsByKey = $this->packagingCostingRowsByKey($costing?->packagingItems ?? collect());
        $existingRowOccurrences = [];

        return $recipeVersion->packagingItems
            ->sortBy('position')
            ->map(function (RecipeVersionPackagingItem $item) use (&$existingRowOccurrences, $existingRowsByKey): RecipeVersionCostingPackagingItem {
                $existingRow = $this->matchingPackagingCostingRow($existingRowsByKey, $item, $existingRowOccurrences);
                $catalogItem = $item->packagingItem;

                return (new RecipeVersionCostingPackagingItem([
                    'product_size_id' => $item->product_size_id,
                    'packaging_item_id' => $item->packaging_item_id,
                    'name' => $item->name,
                    'unit_cost' => $existingRow instanceof RecipeVersionCostingPackagingItem
                        ? $existingRow->unit_cost
                        : $catalogItem?->unit_cost,
                    'quantity' => $item->components_per_unit,
                ]))->setRelation('packagingItem', $catalogItem);
            })->values();
    }

    /**
     * Apply submitted item prices to costing rows and update the workspace current price.
     *
     * For each submitted price, the costing item is updated. If the price is non-null,
     * the workspace current price is also updated so the
     * price can be prefilled in future recipes containing the same ingredient.
     */
    private function applyItemPrices(RecipeVersionCosting $costing, User $user, mixed $rawItems): void
    {
        $workspace = Workspace::withoutGlobalScopes()->findOrFail($costing->recipeVersion->workspace_id);
        $submittedItems = collect(is_array($rawItems) ? $rawItems : [])
            ->filter(fn (mixed $row): bool => is_array($row) && isset($row['ingredient_id'], $row['phase_key'], $row['position']))
            ->keyBy(fn (array $row): string => $this->costingKey(
                (int) $row['ingredient_id'],
                (string) $row['phase_key'],
                (int) $row['position'],
            ));

        $costing->items()->get()->each(function (RecipeVersionCostingItem $item) use ($costing, $submittedItems, $user, $workspace): void {
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
                $ingredient = Ingredient::withoutGlobalScopes()->findOrFail($item->ingredient_id);
                $this->currentMaterialPriceService->rememberIngredient(
                    workspace: $workspace,
                    ingredient: $ingredient,
                    pricePerMassUnit: (string) $item->price_per_kg,
                    massUnit: MassUnit::Kilogram->value,
                    currency: $costing->currency,
                    source: MaterialPriceSource::ManualCosting,
                    sourceId: $costing->id,
                    actor: $user,
                    exceptCostingId: $costing->id,
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
    private function replacePackagingItems(RecipeVersionCosting $costing, User $user, mixed $rawItems): void
    {
        $workspace = Workspace::withoutGlobalScopes()->findOrFail($costing->recipeVersion->workspace_id);
        $submittedItems = collect(is_array($rawItems) ? $rawItems : [])
            ->filter(fn (mixed $row): bool => is_array($row) && filled($row['name'] ?? null))
            ->values();

        $linkedPackagingItems = PackagingItem::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('id', $submittedItems
                ->pluck('packaging_item_id')
                ->filter(fn (mixed $id): bool => is_numeric($id))
                ->map(fn (mixed $id): int => (int) $id)
                ->all())
            ->get()
            ->keyBy('id');

        $productSizeIdsByKey = ProductSize::query()
            ->where('recipe_id', $costing->recipeVersion->recipe_id)
            ->pluck('id', 'public_id');

        $costing->packagingItems()->delete();

        $submittedItems
            ->each(function (array $row) use ($costing, $linkedPackagingItems, $productSizeIdsByKey, $user, $workspace): void {
                $linkedPackagingItemId = isset($row['packaging_item_id']) && is_numeric($row['packaging_item_id'])
                    ? (int) $row['packaging_item_id']
                    : null;
                $unitCost = (float) ($row['unit_cost'] ?? 0);

                $costing->packagingItems()->create([
                    'product_size_id' => is_string($row['product_size_key'] ?? null)
                        ? $productSizeIdsByKey->get($row['product_size_key'])
                        : null,
                    'packaging_item_id' => $linkedPackagingItemId,
                    'name' => trim((string) $row['name']),
                    'unit_cost' => $unitCost,
                    'quantity' => (float) ($row['components_per_unit'] ?? $row['quantity'] ?? 0),
                ]);

                $linkedPackagingItem = $linkedPackagingItems->get($linkedPackagingItemId);

                if (! $linkedPackagingItem instanceof PackagingItem) {
                    return;
                }

                $this->currentMaterialPriceService->rememberPackaging(
                    workspace: $workspace,
                    packagingItem: $linkedPackagingItem,
                    pricePerItem: (string) $unitCost,
                    currency: $costing->currency,
                    source: MaterialPriceSource::ManualCosting,
                    sourceId: $costing->id,
                    actor: $user,
                    exceptCostingId: $costing->id,
                );
            });
    }

    /** Build a composite key for matching costing rows to formula rows. */
    private function costingKey(int $ingredientId, string $phaseKey, int $position): string
    {
        return implode(':', [$ingredientId, $phaseKey, $position]);
    }

    private function packagingKey(?int $productSizeId, ?int $packagingItemId, string $name): string
    {
        return ($productSizeId ?? 'unsized').':'.($packagingItemId ?? 'unlinked').':'.mb_strtolower($name);
    }

    /**
     * The saved price for a packaging line: the same size's row first, then a
     * row saved before prices recorded their size.
     *
     * @param  Collection<string, Collection<int, RecipeVersionCostingPackagingItem>>  $rowsByKey
     * @param  array<string, int>  $occurrences
     */
    private function matchingPackagingCostingRow(Collection $rowsByKey, RecipeVersionPackagingItem $item, array &$occurrences): ?RecipeVersionCostingPackagingItem
    {
        $packagingItemId = $item->packaging_item_id === null ? null : (int) $item->packaging_item_id;
        $productSizeId = $item->product_size_id === null ? null : (int) $item->product_size_id;

        $sizedRow = $productSizeId === null
            ? null
            : $this->nextPackagingCostingRow($rowsByKey, $this->packagingKey($productSizeId, $packagingItemId, $item->name), $occurrences);

        return $sizedRow
            ?? $this->nextPackagingCostingRow($rowsByKey, $this->packagingKey(null, $packagingItemId, $item->name), $occurrences);
    }

    /**
     * @param  Collection<int, RecipeVersionCostingPackagingItem>  $items
     * @return Collection<string, Collection<int, RecipeVersionCostingPackagingItem>>
     */
    private function packagingCostingRowsByKey(Collection $items): Collection
    {
        return $items
            ->groupBy(fn (RecipeVersionCostingPackagingItem $item): string => $this->packagingKey(
                $item->product_size_id === null ? null : (int) $item->product_size_id,
                $item->packaging_item_id === null ? null : (int) $item->packaging_item_id,
                $item->name,
            ))
            ->map(fn (Collection $rows): Collection => $rows->values());
    }

    /**
     * @param  Collection<string, Collection<int, RecipeVersionCostingPackagingItem>>  $rowsByKey
     * @param  array<string, int>  $occurrences
     */
    private function nextPackagingCostingRow(Collection $rowsByKey, string $key, array &$occurrences): ?RecipeVersionCostingPackagingItem
    {
        $index = $occurrences[$key] ?? 0;
        $occurrences[$key] = $index + 1;
        $rows = $rowsByKey->get($key);

        if (! $rows instanceof Collection) {
            return null;
        }

        $row = $rows->get($index);

        return $row instanceof RecipeVersionCostingPackagingItem ? $row : null;
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

    /** Normalize an oil unit to one of the allowed values, defaulting to grams. */
    private function normalizeOilUnit(mixed $value): string
    {
        return MassUnit::tryFrom(is_string($value) ? $value : '')?->value
            ?? MassUnit::Gram->value;
    }

    private function costingDisplayMass(RecipeVersionCosting $costing): ?float
    {
        if ($costing->oil_mass_grams_for_costing !== null) {
            return (float) $this->massConverter->fromGrams(
                $costing->oil_mass_grams_for_costing,
                $costing->oil_unit_for_costing,
            );
        }

        return $costing->oil_weight_for_costing === null
            ? null
            : (float) $costing->oil_weight_for_costing;
    }

    private function formulaCanonicalMass(RecipeVersion $recipeVersion, string $unit): ?string
    {
        if ($recipeVersion->batch_mass_grams !== null) {
            return $recipeVersion->batch_mass_grams;
        }

        if (! is_numeric($recipeVersion->batch_size)) {
            return null;
        }

        return $this->massConverter->toGrams($recipeVersion->batch_size, $unit);
    }
}
