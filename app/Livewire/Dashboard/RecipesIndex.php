<?php

namespace App\Livewire\Dashboard;

use App\IngredientCategory;
use App\Models\Ingredient;
use App\Models\ProductFamily;
use App\Models\Recipe;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
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
                    IngredientCategory::Additive->value,
                    IngredientCategory::Colorant->value,
                    IngredientCategory::Preservative->value,
                ])
                ->count(),
        ];

        $recipeCount = Auth::check() ? Recipe::query()->count() : null;

        return view('livewire.dashboard.recipes-index', [
            'productFamilies' => $productFamilies,
            'catalogStats' => $catalogStats,
            'recipeCount' => $recipeCount,
        ]);
    }
}
