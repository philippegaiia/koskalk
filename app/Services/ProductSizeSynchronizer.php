<?php

namespace App\Services;

use App\Enums\ProductionOutputType;
use App\Enums\ProductionRunStatus;
use App\Models\ProductionRun;
use App\Models\ProductSize;
use App\Models\RecipeVersion;
use App\Models\RecipeVersionPackagingItem;
use App\Models\StockLot;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

class ProductSizeSynchronizer
{
    private const DerivedKeyNamespace = '6f1d2a8e-4b7c-4f0a-9c3e-2d5b8a1f7e64';

    /**
     * Write the submitted sizes onto the Product and archive the ones that
     * were removed. Returns the size id for every submitted key.
     *
     * Re-running with the same sizes is a no-op: a browser key becomes the
     * size's public id, and a key already owned by another Product (a
     * duplicated formula) maps to a stable id derived from Product and key.
     * Publishing relies on this, because it writes two versions from one
     * payload. Callers hold the Product lock.
     *
     * @param  array<int, array{key: string, name: string|null, declared_content_value: string|null, declared_content_unit: string|null, position: int}>  $productSizes
     * @return array<string, int>
     */
    public function sync(int $recipeId, array $productSizes): array
    {
        $existing = ProductSize::query()->where('recipe_id', $recipeId)->get()->keyBy('public_id');
        $idsByKey = [];

        foreach ($productSizes as $index => $row) {
            $size = $existing->get($row['key'])
                ?? $existing->get($this->derivedPublicId($recipeId, $row['key']))
                ?? new ProductSize(['recipe_id' => $recipeId]);

            if (! $size->exists) {
                $size->public_id = $this->newPublicId($recipeId, $row['key']);
            }

            $size->fill([
                'name' => $row['name'],
                'declared_content_value' => $row['declared_content_value'],
                'declared_content_unit' => $row['declared_content_unit'],
                'position' => $row['position'],
                'archived_at' => null,
            ]);

            if ($size->exists
                && $size->isDirty(['declared_content_value', 'declared_content_unit'])
                && ($this->hasFinishedProduction($size) || StockLot::query()->where('product_size_id', $size->id)->exists())) {
                throw ValidationException::withMessages([
                    "product_sizes.{$index}.declared_content_value" => __('workbench.packaging.sizes.validation.in_use_content'),
                ]);
            }

            $size->save();

            $existing->put($size->public_id, $size);
            $idsByKey[$row['key']] = $size->id;
        }

        $this->archiveRemoved($existing, $idsByKey);

        return $idsByKey;
    }

    /**
     * Map another Product's sizes onto this Product's sizes made from them.
     *
     * A duplicated Product keeps the source size's key, or a key derived from
     * it when the source still owns that key, so the same lookup as sync()
     * finds the copy. Sizes with no copy are left out.
     *
     * @param  array<int, int>  $sourceSizeIds
     * @return array<int, int>
     */
    public function copiedSizeIds(int $recipeId, array $sourceSizeIds): array
    {
        $targetIdsByPublicId = ProductSize::query()->where('recipe_id', $recipeId)->pluck('id', 'public_id');

        return ProductSize::query()
            ->whereIn('id', $sourceSizeIds)
            ->pluck('public_id', 'id')
            ->map(fn (string $publicId): ?int => $targetIdsByPublicId->get($publicId)
                ?? $targetIdsByPublicId->get($this->derivedPublicId($recipeId, $publicId)))
            ->filter()
            ->all();
    }

    /**
     * Delete archived sizes without formula or production references.
     */
    public function pruneArchived(int $recipeId): void
    {
        ProductSize::query()
            ->where('recipe_id', $recipeId)
            ->whereNotNull('archived_at')
            ->whereNull('sku')
            ->whereNotIn('id', RecipeVersionPackagingItem::query()
                ->whereNotNull('product_size_id')
                ->select('product_size_id'))
            ->whereNotIn('id', DB::table('recipe_version_costing_size_yields')->select('product_size_id'))
            ->whereNotIn('id', DB::table('recipe_version_product_sizes')->select('product_size_id'))
            ->whereNotIn('id', DB::table('production_runs')->whereNotNull('product_size_id')->select('product_size_id'))
            ->whereNotIn('id', DB::table('production_batches')->whereNotNull('product_size_id')->select('product_size_id'))
            ->whereNotIn('id', StockLot::query()->whereNotNull('product_size_id')->select('product_size_id'))
            ->delete();
    }

    private function hasFinishedProduction(ProductSize $size): bool
    {
        return ProductionRun::query()
            ->where('product_size_id', $size->id)
            ->whereNotIn('status', [ProductionRunStatus::Cancelled, ProductionRunStatus::Aborted])
            ->where(fn ($query) => $query->where('production_output_type', ProductionOutputType::FinishedProduct)
                ->orWhere(fn ($legacy) => $legacy->whereNull('production_output_type')->whereNull('output_ingredient_id')))
            ->exists();
    }

    /** @param list<int> $keptIds */
    public function forgetRemovedYields(RecipeVersion $version, array $keptIds): void
    {
        foreach ($version->costings()->with('sizeYields')->get() as $costing) {
            $removedIds = array_diff($costing->sizeYields->modelKeys(), $keptIds);
            if ($removedIds !== []) {
                $costing->sizeYields()->detach($removedIds);
                $costing->update(['units_produced' => null]);
            }
        }
    }

    /**
     * @param  Collection<string, ProductSize>  $existing
     * @param  array<string, int>  $idsByKey
     */
    private function archiveRemoved(Collection $existing, array $idsByKey): void
    {
        $keptIds = array_values($idsByKey);

        $existing
            ->filter(fn (ProductSize $size): bool => $size->archived_at === null && ! in_array($size->id, $keptIds, true))
            ->each(fn (ProductSize $size): bool => $size->forceFill(['archived_at' => now()])->save());
    }

    private function newPublicId(int $recipeId, string $key): string
    {
        if (Str::isUuid($key) && ! ProductSize::query()->where('public_id', $key)->exists()) {
            return Str::lower($key);
        }

        return $this->derivedPublicId($recipeId, $key);
    }

    private function derivedPublicId(int $recipeId, string $key): string
    {
        return Uuid::uuid5(self::DerivedKeyNamespace, $recipeId.':'.$key)->toString();
    }
}
