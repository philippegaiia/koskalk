<?php

namespace App\Livewire\Dashboard;

use App\IngredientCategory;
use App\Models\Ingredient;
use App\Models\ProductFamily;
use App\Models\Recipe;
use App\Services\CurrentAppUserResolver;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class RecipesIndex extends Component
{
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

        if ($currentUser !== null) {
            $recipes = Recipe::query()
                ->with([
                    'productFamily',
                    'currentDraftVersion',
                ])
                ->withCount([
                    'versions as published_versions_count' => fn ($query) => $query->where('is_draft', false),
                ])
                ->whereNull('archived_at')
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
        ]);
    }
}
