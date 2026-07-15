<?php

namespace App\Services;

use App\Models\IfraProductCategory;
use App\Models\ProductFamily;
use App\Models\ProductFamilyIfraCategory;
use Illuminate\Support\Collection;

class RecipeWorkbenchIfraOptionsBuilder
{
    /** @var array<int, Collection<int, ProductFamilyIfraCategory>> */
    private array $mappingsByProductFamilyId = [];

    /** @var Collection<int, IfraProductCategory>|null */
    private ?Collection $activeCategories = null;

    /**
     * @return array<int, array{id:int, code:string, name:string, short_name:?string, description:?string}>
     */
    public function categories(ProductFamily $productFamily): array
    {
        $mappedCategories = $this->mappings($productFamily)
            ->map(fn (ProductFamilyIfraCategory $mapping): ?IfraProductCategory => $mapping->ifraProductCategory)
            ->filter(fn (?IfraProductCategory $category): bool => $category instanceof IfraProductCategory && $category->is_active)
            ->values();

        $categories = $mappedCategories->isNotEmpty()
            ? $mappedCategories
            : $this->activeCategories();

        return $categories
            ->sortBy(fn (IfraProductCategory $category): array => $this->sortKey($category->code))
            ->values()
            ->map(fn (IfraProductCategory $category): array => [
                'id' => $category->id,
                'code' => $category->code,
                'name' => $category->name,
                'short_name' => $category->short_name,
                'description' => $category->description,
            ])
            ->all();
    }

    public function defaultCategoryId(ProductFamily $productFamily): ?int
    {
        $mappedDefault = $this->mappings($productFamily)
            ->first(fn (ProductFamilyIfraCategory $mapping): bool => $mapping->is_default);

        if ($mappedDefault?->ifraProductCategory?->is_active) {
            return $mappedDefault->ifra_product_category_id;
        }

        return $this->activeCategories()->firstWhere('code', '9')?->id;
    }

    /**
     * @return Collection<int, ProductFamilyIfraCategory>
     */
    private function mappings(ProductFamily $productFamily): Collection
    {
        return $this->mappingsByProductFamilyId[$productFamily->id] ??= ProductFamilyIfraCategory::query()
            ->with('ifraProductCategory')
            ->where('product_family_id', $productFamily->id)
            ->orderBy('sort_order')
            ->orderByDesc('is_default')
            ->get();
    }

    /**
     * @return Collection<int, IfraProductCategory>
     */
    private function activeCategories(): Collection
    {
        return $this->activeCategories ??= IfraProductCategory::query()
            ->where('is_active', true)
            ->get();
    }

    /**
     * @return array{int, string}
     */
    private function sortKey(string $code): array
    {
        preg_match('/^(\d+)([A-Za-z]*)$/', $code, $matches);

        return [
            isset($matches[1]) ? (int) $matches[1] : PHP_INT_MAX,
            strtoupper($matches[2] ?? ''),
        ];
    }
}
