<?php

namespace App\Livewire\Dashboard;

use App\IngredientCategory;
use App\Models\Ingredient;
use App\Models\ProductFamily;
use App\Models\Recipe;
use App\Services\CurrentAppUserResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Livewire\Component;

class RecipesIndex extends Component
{
    #[Url(as: 'q')]
    public string $search = '';

    public function render(): View
    {
        $productFamilies = ProductFamily::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn (ProductFamily $family): array => [
                'name' => $family->name,
                'slug' => $family->slug,
                'basis' => $family->calculation_basis,
                'description' => $family->description,
            ])
            ->all();

        $catalogStats = [
            'carrier_oils' => Ingredient::query()
                ->where('category', IngredientCategory::CarrierOil->value)
                ->where('is_potentially_saponifiable', true)
                ->count(),
            'aromatics' => Ingredient::query()
                ->whereIn('category', IngredientCategory::aromaticValues())
                ->count(),
            'additives' => Ingredient::query()
                ->whereIn('category', [
                    IngredientCategory::BotanicalExtract->value,
                    IngredientCategory::Clay->value,
                    IngredientCategory::Glycol->value,
                    IngredientCategory::Additive->value,
                    IngredientCategory::Colorant->value,
                    IngredientCategory::Preservative->value,
                ])
                ->count(),
        ];

        $currentUser = app(CurrentAppUserResolver::class)->resolve();
        $recipes = collect();
        $recipeCount = 0;
        $draftCount = 0;
        $publishedVersionCount = 0;
        $searchTerm = trim($this->search);

        if ($currentUser !== null) {
            $recipesQuery = Recipe::query()
                ->with([
                    'productFamily',
                    'currentDraftVersion',
                    'publishedVersions' => fn ($query) => $query
                        ->select(['id', 'recipe_id', 'version_number', 'name', 'saved_at'])
                        ->latest('version_number')
                        ->limit(3),
                ])
                ->withCount([
                    'versions as published_versions_count' => fn ($query) => $query->where('is_draft', false),
                ])
                ->whereNull('archived_at');

            if ($searchTerm !== '') {
                $recipesQuery->where(function (Builder $query) use ($searchTerm): void {
                    $query
                        ->where('name', 'ilike', '%'.$searchTerm.'%')
                        ->orWhereHas('productFamily', fn (Builder $familyQuery) => $familyQuery->where('name', 'ilike', '%'.$searchTerm.'%'))
                        ->orWhereHas('versions', fn (Builder $versionQuery) => $versionQuery->where('name', 'ilike', '%'.$searchTerm.'%'));
                });
            }

            $recipes = $recipesQuery
                ->latest()
                ->get();

            $recipeCount = $recipes->count();
            $draftCount = $recipes->filter(fn (Recipe $recipe): bool => $recipe->currentDraftVersion !== null)->count();
            $publishedVersionCount = (int) $recipes->sum('published_versions_count');
        }

        return view('livewire.dashboard.recipes-index', [
            'currentUser' => $currentUser,
            'productFamilies' => $productFamilies,
            'catalogStats' => $catalogStats,
            'recipeCount' => $recipeCount,
            'draftCount' => $draftCount,
            'publishedVersionCount' => $publishedVersionCount,
            'recipes' => $recipes,
            'searchTerm' => $searchTerm,
        ]);
    }
}
