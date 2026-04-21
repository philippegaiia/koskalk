<?php

namespace App\Livewire\Dashboard;

use App\IngredientCategory;
use App\Models\Ingredient;
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
        $catalogStatsRow = Ingredient::query()
            ->selectRaw(
                '
                COALESCE(SUM(CASE WHEN category = ? AND is_potentially_saponifiable = ? THEN 1 ELSE 0 END), 0) as carrier_oils,
                COALESCE(SUM(CASE WHEN category IN (?, ?, ?) THEN 1 ELSE 0 END), 0) as aromatics,
                COALESCE(SUM(CASE WHEN category IN (?, ?, ?, ?, ?, ?) THEN 1 ELSE 0 END), 0) as additives
                ',
                [
                    IngredientCategory::CarrierOil->value,
                    true,
                    ...IngredientCategory::aromaticValues(),
                    IngredientCategory::BotanicalExtract->value,
                    IngredientCategory::Clay->value,
                    IngredientCategory::Glycol->value,
                    IngredientCategory::Additive->value,
                    IngredientCategory::Colorant->value,
                    IngredientCategory::Preservative->value,
                ],
            )
            ->first();

        $catalogStats = [
            'carrier_oils' => (int) ($catalogStatsRow?->carrier_oils ?? 0),
            'aromatics' => (int) ($catalogStatsRow?->aromatics ?? 0),
            'additives' => (int) ($catalogStatsRow?->additives ?? 0),
        ];

        $currentUser = app(CurrentAppUserResolver::class)->resolve();
        $recipes = collect();
        $recipeCount = 0;
        $draftCount = 0;
        $savedFormulaCount = 0;
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
            $draftCount = $recipes->filter(fn (Recipe $recipe): bool => $recipe->currentDraftVersion !== null)->count();
            $savedFormulaCount = $recipes->filter(fn (Recipe $recipe): bool => $recipe->currentSavedVersion !== null)->count();
        }

        return view('livewire.dashboard.recipes-index', [
            'currentUser' => $currentUser,
            'catalogStats' => $catalogStats,
            'recipeCount' => $recipeCount,
            'draftCount' => $draftCount,
            'savedFormulaCount' => $savedFormulaCount,
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
