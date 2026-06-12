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
        $recipeCount = 0;
        $lockedFormulaCount = 0;
        $ingredientCount = 0;

        if ($currentUser !== null) {
            $baseQuery = Recipe::query()->whereNull('archived_at');
            $recipeCount = (clone $baseQuery)->count();
            $lockedFormulaCount = (clone $baseQuery)->whereNotNull('locked_at')->count();
            $ingredientCount = Ingredient::query()
                ->accessibleTo($currentUser)
                ->count();
        }

        return view('dashboard', [
            'currentUser' => $currentUser,
            'recipeCount' => $recipeCount,
            'lockedFormulaCount' => $lockedFormulaCount,
            'ingredientCount' => $ingredientCount,
        ]);
    }
}
