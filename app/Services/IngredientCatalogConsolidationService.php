<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\WorkspaceIngredientCode;
use App\Support\IngredientCatalogConsolidationDataset;
use App\Support\IngredientCatalogTaxonomyDataset;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class IngredientCatalogConsolidationService
{
    /** @var array<string, string> */
    private const MERGE_REFERENCE_COLUMNS = [
        'ingredient_components' => 'component_ingredient_id',
        'production_batch_ingredients' => 'ingredient_id',
        'production_formula_lines' => 'ingredient_id',
        'production_requirements' => 'ingredient_id',
        'purchase_order_lines' => 'ingredient_id',
        'recipe_items' => 'ingredient_id',
        'recipe_version_costing_items' => 'ingredient_id',
        'stock_lots' => 'ingredient_id',
        'supplier_listings' => 'ingredient_id',
    ];

    public function __construct(
        private readonly IngredientCatalogTaxonomyDataset $taxonomy,
        private readonly IngredientCatalogConsolidationDataset $consolidation,
    ) {}

    /**
     * @return Collection<int, array{catalog_key:string, display_name:string, from:?string, to:?string, subcategory:?string, status:string}>
     */
    public function preview(): Collection
    {
        return Ingredient::query()
            ->whereNull('owner_type')
            ->orderBy('id')
            ->get()
            ->map(function (Ingredient $ingredient): array {
                $mapping = $this->taxonomy->assignmentFor($ingredient);

                return [
                    'catalog_key' => (string) $ingredient->catalog_key,
                    'display_name' => (string) $ingredient->display_name,
                    'from' => $ingredient->category?->value,
                    'to' => $mapping['category']->value ?? null,
                    'subcategory' => $mapping['subcategory']?->value ?? null,
                    'status' => $mapping === null ? 'missing_metadata' : 'ready',
                ];
            });
    }

    /**
     * Enforce the review gate before applying non-destructive metadata.
     * Approved merge/remove decisions stay blocked until dependency
     * reconciliation is implemented and tested.
     *
     * @return array{updated:int,unchanged:int,reviewed:int,merged:int,removed:int}
     */
    public function apply(): array
    {
        $unresolvedKeys = collect($this->consolidation->all())
            ->where('action', 'review')
            ->pluck('source_catalog_key')
            ->filter(fn (string $catalogKey): bool => Ingredient::query()
                ->whereNull('owner_type')
                ->where('catalog_key', $catalogKey)
                ->exists())
            ->values()
            ->all();

        if ($unresolvedKeys !== []) {
            throw new RuntimeException('Unresolved consolidation decisions: '.implode(', ', $unresolvedKeys).'. Review the decision file before applying.');
        }

        return DB::transaction(function (): array {
            $merged = 0;
            $removed = 0;

            foreach ($this->consolidation->all() as $decision) {
                if ($decision['action'] === 'merge_into') {
                    $this->merge($decision['source_catalog_key'], (string) $decision['target_catalog_key']);
                    $merged++;
                }

                if ($decision['action'] === 'remove') {
                    $this->remove($decision['source_catalog_key']);
                    $removed++;
                }
            }

            return [
                ...$this->applyMetadata(),
                'merged' => $merged,
                'removed' => $removed,
            ];
        });
    }

    /**
     * Apply only reviewed taxonomy and capability metadata. This method is
     * intentionally separate from destructive catalogue consolidation.
     *
     * @return array{updated:int,unchanged:int,reviewed:int}
     */
    public function applyMetadata(): array
    {
        $missingCatalogKeys = $this->preview()
            ->where('status', 'missing_metadata')
            ->pluck('catalog_key')
            ->all();

        if ($missingCatalogKeys !== []) {
            throw new RuntimeException('Exact taxonomy metadata is missing for: '.implode(', ', $missingCatalogKeys).'.');
        }

        return DB::transaction(function (): array {
            $updated = 0;
            $unchanged = 0;

            Ingredient::query()
                ->whereNull('owner_type')
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->each(function (Ingredient $ingredient) use (&$updated, &$unchanged): void {
                    $mapping = $this->taxonomy->assignmentFor($ingredient);

                    if ($mapping === null) {
                        throw new RuntimeException("Exact taxonomy metadata is missing for [{$ingredient->catalog_key}].");
                    }

                    $next = [
                        'category' => $mapping['category'],
                        'subcategory' => $mapping['subcategory'],
                        'taxonomy_source' => 'platform_curated',
                        'requires_aromatic_compliance' => $mapping['requires_aromatic_compliance'],
                        'is_soap_saponification_trusted' => $mapping['is_soap_saponification_trusted'],
                    ];

                    if ($ingredient->category === $next['category']
                        && $ingredient->subcategory === $next['subcategory']
                        && $ingredient->taxonomy_source === $next['taxonomy_source']
                        && $ingredient->requires_aromatic_compliance === $next['requires_aromatic_compliance']
                        && $ingredient->is_soap_saponification_trusted === $next['is_soap_saponification_trusted']) {
                        $unchanged++;

                        return;
                    }

                    $ingredient->forceFill($next)->save();
                    $updated++;
                });

            return [
                'updated' => $updated,
                'unchanged' => $unchanged,
                'reviewed' => $updated + $unchanged,
            ];
        });
    }

    private function merge(string $sourceCatalogKey, string $targetCatalogKey): void
    {
        $ingredients = Ingredient::query()
            ->whereNull('owner_type')
            ->whereIn('catalog_key', [$sourceCatalogKey, $targetCatalogKey])
            ->lockForUpdate()
            ->get()
            ->keyBy('catalog_key');
        $source = $ingredients->get($sourceCatalogKey);
        $target = $ingredients->get($targetCatalogKey);

        if (! $source instanceof Ingredient || ! $target instanceof Ingredient) {
            throw new RuntimeException("Approved merge [{$sourceCatalogKey} -> {$targetCatalogKey}] requires both platform ingredients.");
        }

        $this->mergeWorkspaceMaterialCodes($source, $target);
        $this->mergeWorkspacePrices($source, $target);

        foreach (self::MERGE_REFERENCE_COLUMNS as $table => $column) {
            DB::table($table)->where($column, $source->id)->update([$column => $target->id]);
        }

        DB::table('media_asset_usages')
            ->where('usable_type', $source->getMorphClass())
            ->where('usable_id', $source->id)
            ->update(['usable_id' => $target->id]);

        $source->delete();
    }

    private function mergeWorkspaceMaterialCodes(Ingredient $source, Ingredient $target): void
    {
        $codes = WorkspaceIngredientCode::query()
            ->whereIn('ingredient_id', [$source->id, $target->id])
            ->orderBy('workspace_id')
            ->lockForUpdate()
            ->get()
            ->groupBy('workspace_id');

        foreach ($codes as $workspaceId => $workspaceCodes) {
            $sourceCode = $workspaceCodes->firstWhere('ingredient_id', $source->id);

            if (! $sourceCode instanceof WorkspaceIngredientCode) {
                continue;
            }

            $targetCode = $workspaceCodes->firstWhere('ingredient_id', $target->id);

            if (! $targetCode instanceof WorkspaceIngredientCode) {
                $sourceCode->update(['ingredient_id' => $target->id]);

                continue;
            }

            if ($sourceCode->material_code === $targetCode->material_code) {
                $sourceCode->delete();

                continue;
            }

            throw new RuntimeException("Cannot merge {$source->catalog_key} into {$target->catalog_key}: workspace material code conflict for workspace {$workspaceId}.");
        }
    }

    private function mergeWorkspacePrices(Ingredient $source, Ingredient $target): void
    {
        $prices = DB::table('current_material_prices')
            ->whereIn('ingredient_id', [$source->id, $target->id])
            ->orderBy('workspace_id')
            ->lockForUpdate()
            ->get()
            ->groupBy('workspace_id');

        foreach ($prices as $workspaceId => $workspacePrices) {
            $sourcePrice = $workspacePrices->firstWhere('ingredient_id', $source->id);

            if ($sourcePrice === null) {
                continue;
            }

            $targetPrice = $workspacePrices->firstWhere('ingredient_id', $target->id);

            if ($targetPrice !== null) {
                if ($sourcePrice->currency !== $targetPrice->currency
                    || $sourcePrice->price_per_canonical_unit !== $targetPrice->price_per_canonical_unit) {
                    throw new RuntimeException("Cannot merge {$source->catalog_key} into {$target->catalog_key}: workspace price conflict for workspace {$workspaceId}.");
                }

                DB::table('current_material_prices')->where('id', $sourcePrice->id)->delete();

                continue;
            }

            DB::table('current_material_prices')
                ->where('id', $sourcePrice->id)
                ->update(['ingredient_id' => $target->id]);
        }
    }

    private function remove(string $sourceCatalogKey): void
    {
        $source = Ingredient::query()
            ->whereNull('owner_type')
            ->where('catalog_key', $sourceCatalogKey)
            ->lockForUpdate()
            ->first();

        if (! $source instanceof Ingredient) {
            return;
        }

        $blockingTables = collect([
            'current_material_prices' => 'ingredient_id',
            ...self::MERGE_REFERENCE_COLUMNS,
        ])->filter(fn (string $column, string $table): bool => DB::table($table)
            ->where($column, $source->id)
            ->exists())
            ->keys()
            ->all();

        if ($blockingTables !== []) {
            throw new RuntimeException("Cannot remove {$sourceCatalogKey}; dependencies remain in: ".implode(', ', $blockingTables).'.');
        }

        DB::table('media_asset_usages')
            ->where('usable_type', $source->getMorphClass())
            ->where('usable_id', $source->id)
            ->delete();
        $source->delete();
    }
}
