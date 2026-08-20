<?php

namespace App\Services;

use App\Models\ProductArea;
use App\Models\ProductCategory;
use App\Models\ProductFamily;
use App\Models\ProductType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

class ProductCreationCatalog
{
    /** @var array<string, array{family: string, area: string|null}> */
    private const ENTRIES = [
        'soap' => ['family' => 'soap', 'area' => null],
        'cosmetics' => ['family' => 'cosmetic', 'area' => 'personal-care'],
        'home' => ['family' => 'cosmetic', 'area' => 'home-household'],
    ];

    /**
     * @return array<string, array{family: string, area: string|null, name: string, description: string}>
     */
    public function entries(): array
    {
        return collect(self::ENTRIES)
            ->map(fn (array $configuration, string $entry): array => [
                ...$configuration,
                'name' => __("products.creation.entries.{$entry}.name"),
                'description' => __("products.creation.entries.{$entry}.description"),
            ])
            ->all();
    }

    /**
     * @return array<int, array{id: int, name: string, slug: string, categories: array<int, array{id: int, name: string, slug: string, product_types: array<int, array{id: int, name: string, slug: string, description: string|null}>}>}>
     */
    public function groupedTypes(string $entry): array
    {
        $configuration = self::ENTRIES[$entry] ?? null;

        if ($configuration === null) {
            throw new InvalidArgumentException("Unknown Product creation entry [{$entry}].");
        }

        $productFamily = ProductFamily::query()
            ->where('slug', $configuration['family'])
            ->where('is_active', true)
            ->firstOrFail();

        return ProductArea::query()
            ->where('is_active', true)
            ->when(
                $configuration['area'] !== null,
                fn (Builder $query): Builder => $query->where('slug', $configuration['area']),
            )
            ->whereHas(
                'productCategories',
                fn (Builder $query): Builder => $query
                    ->where('is_active', true)
                    ->whereHas(
                        'productTypes',
                        fn (Builder $query): Builder => $query
                            ->where('is_active', true)
                            ->whereHas(
                                'productFamilies',
                                fn (Builder $query): Builder => $query->whereKey($productFamily->id),
                            ),
                    ),
            )
            ->with([
                'productCategories' => function (HasMany $relation) use ($productFamily): void {
                    $relation->where('is_active', true)
                        ->whereHas(
                            'productTypes.productFamilies',
                            fn (Builder $query): Builder => $query->whereKey($productFamily->id),
                        )
                        ->orderBy('sort_order')
                        ->orderBy('name');
                },
                'productCategories.productTypes' => function (HasMany $relation) use ($productFamily): void {
                    $relation->where('is_active', true)
                        ->whereHas(
                            'productFamilies',
                            fn (Builder $query): Builder => $query->whereKey($productFamily->id),
                        )
                        ->orderBy('sort_order')
                        ->orderBy('name');
                },
            ])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (ProductArea $area): array => [
                'id' => $area->id,
                'name' => $area->name,
                'slug' => $area->slug,
                'categories' => $area->productCategories
                    ->map(fn (ProductCategory $category): array => [
                        'id' => $category->id,
                        'name' => $category->name,
                        'slug' => $category->slug,
                        'product_types' => $category->productTypes
                            ->map(fn (ProductType $productType): array => [
                                'id' => $productType->id,
                                'name' => $productType->name,
                                'slug' => $productType->slug,
                                'description' => $productType->description,
                            ])
                            ->all(),
                    ])
                    ->all(),
            ])
            ->all();
    }
}
