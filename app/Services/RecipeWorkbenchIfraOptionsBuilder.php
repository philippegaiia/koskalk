<?php

namespace App\Services;

use App\Models\IfraProductCategory;
use App\Models\ProductFamily;
use App\Models\ProductFamilyIfraCategory;

class RecipeWorkbenchIfraOptionsBuilder
{
    /**
     * @return array<int, array{id:int, code:string, name:string, short_name:?string, description:?string}>
     */
    public function categories(ProductFamily $productFamily): array
    {
        $mappedCategories = ProductFamilyIfraCategory::query()
            ->with('ifraProductCategory')
            ->where('product_family_id', $productFamily->id)
            ->orderBy('sort_order')
            ->orderByDesc('is_default')
            ->get()
            ->map(fn (ProductFamilyIfraCategory $mapping): ?IfraProductCategory => $mapping->ifraProductCategory)
            ->filter(fn (?IfraProductCategory $category): bool => $category instanceof IfraProductCategory && $category->is_active)
            ->values();

        $categories = $mappedCategories->isNotEmpty()
            ? $mappedCategories
            : IfraProductCategory::query()
                ->where('is_active', true)
                ->get();

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
        $mappedDefault = ProductFamilyIfraCategory::query()
            ->with('ifraProductCategory:id,is_active')
            ->where('product_family_id', $productFamily->id)
            ->where('is_default', true)
            ->orderBy('sort_order')
            ->first();

        if ($mappedDefault?->ifraProductCategory?->is_active) {
            return $mappedDefault->ifra_product_category_id;
        }

        return IfraProductCategory::query()
            ->where('is_active', true)
            ->where('code', '9')
            ->value('id');
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
