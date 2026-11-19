<?php

namespace App\Services;

use App\Models\ProductSize;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Services\Production\ProductionSizeSelection;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductSizeResolver
{
    /** @return Collection<int, ProductSize> */
    public function forVersion(RecipeVersion $version, bool $costing = false, bool $preferPackaged = false): Collection
    {
        $packagedSizeIds = $version->relationLoaded('packagingItems')
            ? $version->packagingItems->pluck('product_size_id')
            : $version->packagingItems()->pluck('product_size_id');
        $packagedSizeIds = $packagedSizeIds->filter()->unique();
        $versionSizeIds = $packagedSizeIds;

        if (! $version->is_current) {
            $versionSizeIds = $versionSizeIds
                ->merge($version->productSizes()->pluck('product_sizes.id'))
                ->merge(DB::table('recipe_version_costing_size_yields')
                    ->whereIn('recipe_version_costing_id', $version->costings()->select('recipe_version_costings.id'))
                    ->pluck('product_size_id'))->unique();
        }

        $sizes = ProductSize::query()->where('recipe_id', $version->recipe_id)
            ->where(function ($query) use ($costing, $preferPackaged, $version, $versionSizeIds): void {
                if (($costing || $preferPackaged) && ! $version->is_current) {
                    $query->whereIn('id', $versionSizeIds);

                    return;
                }

                $query->whereNull('archived_at')->orWhereIn('id', $versionSizeIds);
            })
            ->orderBy('position')->orderBy('id')->get();

        return $costing || $preferPackaged
            ? $sizes->sortBy(fn (ProductSize $size): bool => ! $packagedSizeIds->contains($size->id))->values()
            : $sizes;
    }

    public function selectForProduction(RecipeVersion $version, mixed $key = null): ProductionSizeSelection
    {
        $sizes = $this->forVersion($version, preferPackaged: true);
        $default = $sizes->first();

        if ($key === null || $key === '') {
            if ($sizes->count() > 1) {
                throw ValidationException::withMessages([
                    'packaging' => __('batch_log.validation.size_required'),
                ]);
            }

            return new ProductionSizeSelection($default, true);
        }

        $size = is_string($key) ? $sizes->firstWhere('public_id', strtolower($key)) : null;
        if (! $size instanceof ProductSize) {
            throw ValidationException::withMessages(['packaging' => __('batch_log.validation.invalid_size')]);
        }

        return new ProductionSizeSelection($size, $size->is($default));
    }

    /** @return Collection<int, ProductSize> */
    public function forLatestPublishedVersion(?Recipe $recipe): Collection
    {
        $version = $recipe?->publishedVersions()->withoutGlobalScopes()
            ->where('workspace_id', $recipe->workspace_id)->first();

        return $version instanceof RecipeVersion ? $this->forVersion($version, preferPackaged: true) : new Collection;
    }

    /**
     * Return the Product's first size, creating one from the Product's nominal
     * content when the Product has none yet. Callers hold the Product lock.
     */
    public function defaultForProduct(int $recipeId): ProductSize
    {
        $existing = ProductSize::query()
            ->where('recipe_id', $recipeId)
            ->whereNull('archived_at')
            ->orderBy('position')
            ->orderBy('id')
            ->first();

        if ($existing instanceof ProductSize) {
            return $existing;
        }

        $recipe = Recipe::withoutGlobalScopes()
            ->select(['id', 'nominal_content_value', 'nominal_content_unit'])
            ->findOrFail($recipeId);

        return ProductSize::query()->create([
            'recipe_id' => $recipeId,
            'declared_content_value' => $recipe->nominal_content_value,
            'declared_content_unit' => $recipe->nominal_content_value === null ? null : $recipe->nominal_content_unit,
            'position' => 1,
        ]);
    }
}
