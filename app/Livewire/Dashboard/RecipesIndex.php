<?php

namespace App\Livewire\Dashboard;

use App\Models\ProductArea;
use App\Models\ProductCategory;
use App\Models\ProductType;
use App\Models\Recipe;
use App\Services\CurrentAppUserResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Livewire\Component;

class RecipesIndex extends Component
{
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

    public function render(): View
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

            $optionRecipes = Recipe::query()
                ->with([
                    'productType.productCategory.productArea',
                ])
                ->get(['id', 'product_family_id', 'product_type_id']);

            $productAreaOptions = $this->productAreaOptions($optionRecipes);
            $productCategoryOptions = $this->productCategoryOptions($optionRecipes, $selectedProductArea);
            $productTypeOptions = $this->productTypeOptions($optionRecipes, $selectedProductArea, $selectedProductCategory);

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
                ->get();

            $recipeCount = $recipes->count();
        }

        return view('livewire.dashboard.recipes-index', [
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

    public function updatedArchivedFilter(): void
    {
        $this->resetPage();
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
    }

    /**
     * @param  Collection<int, Recipe>  $recipes
     * @return Collection<string, string>
     */
    private function productAreaOptions(Collection $recipes): Collection
    {
        return $recipes
            ->map(fn (Recipe $recipe): ?ProductArea => $recipe->productType?->productCategory?->productArea)
            ->filter()
            ->unique('slug')
            ->sortBy(fn (ProductArea $productArea): array => [$productArea->sort_order, $productArea->name])
            ->mapWithKeys(fn (ProductArea $productArea): array => [$productArea->slug => $productArea->name]);
    }

    /**
     * @param  Collection<int, Recipe>  $recipes
     * @return Collection<string, string>
     */
    private function productCategoryOptions(Collection $recipes, string $selectedProductArea): Collection
    {
        return $recipes
            ->filter(fn (Recipe $recipe): bool => $selectedProductArea === '' || $recipe->productType?->productCategory?->productArea?->slug === $selectedProductArea)
            ->map(fn (Recipe $recipe): ?ProductCategory => $recipe->productType?->productCategory)
            ->filter()
            ->unique('slug')
            ->sortBy(fn (ProductCategory $productCategory): array => [$productCategory->sort_order, $productCategory->name])
            ->mapWithKeys(fn (ProductCategory $productCategory): array => [$productCategory->slug => $productCategory->name]);
    }

    /**
     * @param  Collection<int, Recipe>  $recipes
     * @return Collection<string, string>
     */
    private function productTypeOptions(Collection $recipes, string $selectedProductArea, string $selectedProductCategory): Collection
    {
        return $recipes
            ->filter(fn (Recipe $recipe): bool => $selectedProductArea === '' || $recipe->productType?->productCategory?->productArea?->slug === $selectedProductArea)
            ->filter(fn (Recipe $recipe): bool => $selectedProductCategory === '' || $recipe->productType?->productCategory?->slug === $selectedProductCategory)
            ->map(fn (Recipe $recipe): ?ProductType => $recipe->productType)
            ->filter()
            ->unique('slug')
            ->sortBy('sort_order')
            ->mapWithKeys(fn (ProductType $productType): array => [$productType->slug => $productType->name]);
    }
}
