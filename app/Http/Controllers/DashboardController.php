<?php

namespace App\Http\Controllers;

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
                ->limit(10)
                ->get();

            $recipeCount = $recipes->count();
            $draftCount = $recipes->filter(fn (Recipe $recipe): bool => $recipe->currentDraftVersion !== null)->count();
            $publishedVersionCount = (int) $recipes->sum('published_versions_count');
        }

        return view('dashboard', [
            'currentUser' => $currentUser,
            'recipes' => $recipes,
            'recipeCount' => $recipeCount,
            'draftCount' => $draftCount,
            'publishedVersionCount' => $publishedVersionCount,
            'personalIngredientCount' => 0,
        ]);
    }
}
