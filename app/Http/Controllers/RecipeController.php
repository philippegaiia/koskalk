<?php

namespace App\Http\Controllers;

use App\Models\Recipe;
use App\Services\CurrentAppUserResolver;
use Illuminate\Contracts\View\View;

class RecipeController extends Controller
{
    public function index(): View
    {
        return view('recipes.index');
    }

    public function create(): View
    {
        return view('recipes.workbench');
    }

    public function edit(int $recipe, CurrentAppUserResolver $currentAppUserResolver): View
    {
        $user = $currentAppUserResolver->resolve();
        $recipe = Recipe::withoutGlobalScopes()->findOrFail($recipe);

        abort_unless($user !== null && $recipe->isAccessibleBy($user), 404);

        return view('recipes.workbench', [
            'recipe' => $recipe,
        ]);
    }
}
