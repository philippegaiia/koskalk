<?php

namespace App\Livewire\Dashboard;

use App\Models\ProductFamily;
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

    #[Url(as: 'family')]
    public string $productFamilyFilter = '';

    #[Url(as: 'type')]
    public string $productTypeFilter = '';

    public function render(): View
    {
        $currentUser = app(CurrentAppUserResolver::class)->resolve();
        $recipes = collect();
        $recipeCount = 0;
        $searchTerm = trim($this->search);
        $selectedProductFamily = trim($this->productFamilyFilter);
        $selectedProductType = trim($this->productTypeFilter);
        $productFamilyOptions = collect();
        $productTypeOptions = collect();

        if ($currentUser !== null) {
            $recipesQuery = Recipe::query()
                ->with([
                    'productFamily',
                    'productType',
                    'currentDraftVersion',
                    'currentSavedVersion',
                ])
                ->whereNull('archived_at');

            $optionRecipesQuery = clone $recipesQuery;
            $optionRecipesQuery->setEagerLoads([]);

            $optionRecipes = $optionRecipesQuery
                ->with([
                    'productFamily',
                    'productType',
                ])
                ->get(['id', 'product_family_id', 'product_type_id']);

            $productFamilyOptions = $this->productFamilyOptions($optionRecipes);
            $productTypeOptions = $this->productTypeOptions($optionRecipes, $selectedProductFamily);

            if ($selectedProductFamily !== '') {
                $recipesQuery->whereHas(
                    'productFamily',
                    fn (Builder $familyQuery) => $familyQuery->where('slug', $selectedProductFamily),
                );
            }

            if ($selectedProductType !== '') {
                $recipesQuery->whereHas(
                    'productType',
                    fn (Builder $typeQuery) => $typeQuery->where('slug', $selectedProductType),
                );
            }

            if ($searchTerm !== '') {
                $searchOperator = $this->caseInsensitiveLikeOperator();
                $searchValue = '%'.$searchTerm.'%';

                $recipesQuery->where(function (Builder $query) use ($searchOperator, $searchValue): void {
                    $query
                        ->where('name', $searchOperator, $searchValue)
                        ->orWhereHas('productFamily', fn (Builder $familyQuery) => $familyQuery->where('name', $searchOperator, $searchValue))
                        ->orWhereHas('productType', fn (Builder $typeQuery) => $typeQuery->where('name', $searchOperator, $searchValue));
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
            'productFamilyOptions' => $productFamilyOptions,
            'productTypeOptions' => $productTypeOptions,
            'selectedProductFamily' => $selectedProductFamily,
            'selectedProductType' => $selectedProductType,
            'recipes' => $recipes,
            'searchTerm' => $searchTerm,
        ]);
    }

    public function updatedProductFamilyFilter(): void
    {
        $this->productTypeFilter = '';
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->productFamilyFilter = '';
        $this->productTypeFilter = '';
    }

    private function caseInsensitiveLikeOperator(): string
    {
        return Recipe::query()->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
    }

    /**
     * @param  Collection<int, Recipe>  $recipes
     * @return Collection<string, string>
     */
    private function productFamilyOptions(Collection $recipes): Collection
    {
        return $recipes
            ->map(fn (Recipe $recipe): ?ProductFamily => $recipe->productFamily)
            ->filter()
            ->unique('slug')
            ->sortBy('name')
            ->mapWithKeys(fn (ProductFamily $productFamily): array => [$productFamily->slug => $productFamily->name]);
    }

    /**
     * @param  Collection<int, Recipe>  $recipes
     * @return Collection<string, string>
     */
    private function productTypeOptions(Collection $recipes, string $selectedProductFamily): Collection
    {
        return $recipes
            ->filter(fn (Recipe $recipe): bool => $selectedProductFamily === '' || $recipe->productFamily?->slug === $selectedProductFamily)
            ->map(fn (Recipe $recipe): ?ProductType => $recipe->productType)
            ->filter()
            ->unique('slug')
            ->sortBy('sort_order')
            ->mapWithKeys(fn (ProductType $productType): array => [$productType->slug => $productType->name]);
    }
}
