<?php

namespace App\Livewire\Dashboard;

use App\Models\ProductArea;
use App\Models\ProductCategory;
use App\Models\ProductType;
use App\Models\Recipe;
use App\Services\ContextualHelp\ApplicationHelpTopics;
use App\Services\CurrentAppUserResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class RecipesIndex extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'area')]
    public string $productAreaFilter = '';

    #[Url(as: 'category')]
    public string $productCategoryFilter = '';

    #[Url(as: 'type')]
    public string $productTypeFilter = '';

    #[Url(as: 'archived')]
    public string $archivedFilter = 'active';

    public function render(ApplicationHelpTopics $helpTopics): View
    {
        $currentUser = app(CurrentAppUserResolver::class)->resolve();
        $recipes = collect();
        $recipeCount = 0;
        $searchTerm = trim($this->search);
        $selectedProductArea = trim($this->productAreaFilter);
        $selectedProductCategory = trim($this->productCategoryFilter);
        $selectedProductType = trim($this->productTypeFilter);
        $productAreaOptions = collect();
        $productCategoryOptions = collect();
        $productTypeOptions = collect();

        if ($currentUser !== null) {
            $recipesQuery = Recipe::query()
                ->with([
                    'productFamily',
                    'productType.productCategory.productArea',
                    'currentVersion',
                    'latestPublishedVersion',
                    'mediaAssetUsages.mediaAsset',
                ])
                ->withCount('productionRuns');

            $this->scopeArchiveState($recipesQuery);

            $availableProductTypes = ProductType::query()
                ->with('productCategory.productArea')
                ->whereIn('id', Recipe::query()->select('product_type_id'))
                ->get();

            $productAreaOptions = $this->productAreaOptions($availableProductTypes);
            $productCategoryOptions = $this->productCategoryOptions($availableProductTypes, $selectedProductArea);
            $productTypeOptions = $this->productTypeOptions($availableProductTypes, $selectedProductArea, $selectedProductCategory);

            if ($selectedProductArea !== '') {
                $recipesQuery->whereHas(
                    'productType.productCategory.productArea',
                    fn (Builder $areaQuery) => $areaQuery->where('slug', $selectedProductArea),
                );
            }

            if ($selectedProductCategory !== '') {
                $recipesQuery->whereHas(
                    'productType.productCategory',
                    fn (Builder $categoryQuery) => $categoryQuery->where('slug', $selectedProductCategory),
                );
            }

            if ($selectedProductType !== '') {
                $recipesQuery->whereHas(
                    'productType',
                    fn (Builder $typeQuery) => $typeQuery->where('slug', $selectedProductType),
                );
            }

            if ($searchTerm !== '') {
                $searchOperator = $recipesQuery->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
                $searchValue = '%'.$searchTerm.'%';

                $recipesQuery->where(function (Builder $query) use ($searchOperator, $searchValue): void {
                    $query
                        ->where('name', $searchOperator, $searchValue)
                        ->orWhereHas('productType', fn (Builder $typeQuery) => $typeQuery->where('name', $searchOperator, $searchValue))
                        ->orWhereHas('productType.productCategory', fn (Builder $categoryQuery) => $categoryQuery->where('name', $searchOperator, $searchValue))
                        ->orWhereHas('productType.productCategory.productArea', fn (Builder $areaQuery) => $areaQuery->where('name', $searchOperator, $searchValue));
                });
            }

            $recipes = $recipesQuery
                ->latest()
                ->orderByDesc('id')
                ->paginate(12);

            $recipeCount = $recipes->total();
        }

        return view('livewire.dashboard.recipes-index', [
            'contextualHelp' => $helpTopics->resolve('products', app()->getLocale()),
            'currentUser' => $currentUser,
            'recipeCount' => $recipeCount,
            'productAreaOptions' => $productAreaOptions,
            'productCategoryOptions' => $productCategoryOptions,
            'productTypeOptions' => $productTypeOptions,
            'selectedProductArea' => $selectedProductArea,
            'selectedProductCategory' => $selectedProductCategory,
            'selectedProductType' => $selectedProductType,
            'recipes' => $recipes,
            'searchTerm' => $searchTerm,
            'archivedFilter' => $this->archivedFilter,
        ]);
    }

    private function scopeArchiveState(Builder $query): void
    {
        match ($this->archivedFilter) {
            'archived' => $query->whereNotNull('archived_at'),
            'active' => $query->whereNull('archived_at'),
            default => null,
        };
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'archivedFilter', 'productAreaFilter', 'productCategoryFilter', 'productTypeFilter'], true)) {
            $this->resetPage();
        }
    }

    public function updatedProductAreaFilter(): void
    {
        $this->productCategoryFilter = '';
        $this->productTypeFilter = '';
    }

    public function updatedProductCategoryFilter(): void
    {
        $this->productTypeFilter = '';
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->productAreaFilter = '';
        $this->productCategoryFilter = '';
        $this->productTypeFilter = '';
        $this->archivedFilter = 'active';
        $this->resetPage();
    }

    /**
     * @param  Collection<int, ProductType>  $productTypes
     * @return Collection<string, string>
     */
    private function productAreaOptions(Collection $productTypes): Collection
    {
        return $productTypes
            ->map(fn (ProductType $productType): ?ProductArea => $productType->productCategory?->productArea)
            ->filter()
            ->unique('slug')
            ->sortBy(fn (ProductArea $productArea): array => [$productArea->sort_order, $productArea->localizedName()])
            ->mapWithKeys(fn (ProductArea $productArea): array => [$productArea->slug => $productArea->localizedName()]);
    }

    /**
     * @param  Collection<int, ProductType>  $productTypes
     * @return Collection<string, string>
     */
    private function productCategoryOptions(Collection $productTypes, string $selectedProductArea): Collection
    {
        return $productTypes
            ->filter(fn (ProductType $productType): bool => $selectedProductArea === '' || $productType->productCategory?->productArea?->slug === $selectedProductArea)
            ->map(fn (ProductType $productType): ?ProductCategory => $productType->productCategory)
            ->filter()
            ->unique('slug')
            ->sortBy(fn (ProductCategory $productCategory): array => [$productCategory->sort_order, $productCategory->localizedName()])
            ->mapWithKeys(fn (ProductCategory $productCategory): array => [$productCategory->slug => $productCategory->localizedName()]);
    }

    /**
     * @param  Collection<int, ProductType>  $productTypes
     * @return Collection<string, string>
     */
    private function productTypeOptions(Collection $productTypes, string $selectedProductArea, string $selectedProductCategory): Collection
    {
        return $productTypes
            ->filter(fn (ProductType $productType): bool => $selectedProductArea === '' || $productType->productCategory?->productArea?->slug === $selectedProductArea)
            ->filter(fn (ProductType $productType): bool => $selectedProductCategory === '' || $productType->productCategory?->slug === $selectedProductCategory)
            ->filter()
            ->unique('slug')
            ->sortBy('sort_order')
            ->mapWithKeys(fn (ProductType $productType): array => [$productType->slug => $productType->localizedName()]);
    }
}
