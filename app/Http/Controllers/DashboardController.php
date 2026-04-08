<?php

namespace App\Http\Controllers;

use App\Models\Ingredient;
use App\Models\Recipe;
use App\Services\CurrentAppUserResolver;
use Illuminate\Contracts\View\View;

class DashboardController extends Controller
{
    public function index(CurrentAppUserResolver $currentAppUserResolver): View
    {
        $currentUser = $currentAppUserResolver->resolve();
        $recipes = collect();
        $recipeCount = 0;
        $draftCount = 0;
        $savedFormulaCount = 0;
        $personalIngredients = collect();
        $personalIngredientCount = 0;

        if ($currentUser !== null) {
            $recipes = Recipe::query()
                ->with([
                    'productFamily',
                    'currentDraftVersion',
                    'currentSavedVersion',
                ])
                ->whereNull('archived_at')
                ->latest()
                ->limit(10)
                ->get();

            $recipeCount = $recipes->count();
            $draftCount = $recipes->filter(fn (Recipe $recipe): bool => $recipe->currentDraftVersion !== null)->count();
            $savedFormulaCount = $recipes->filter(fn (Recipe $recipe): bool => $recipe->currentSavedVersion !== null)->count();

            $personalIngredients = Ingredient::query()
                ->ownedByUser($currentUser)
                ->withCount('components')
                ->latest()
                ->limit(4)
                ->get();

            $personalIngredientCount = Ingredient::query()
                ->ownedByUser($currentUser)
                ->count();
        }

        return view('dashboard', [
            'currentUser' => $currentUser,
            'recipes' => $recipes,
            'recipeCount' => $recipeCount,
            'draftCount' => $draftCount,
            'savedFormulaCount' => $savedFormulaCount,
            'personalIngredients' => $personalIngredients,
            'personalIngredientCount' => $personalIngredientCount,
        ]);
    }
}
