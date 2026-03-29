<?php

namespace App\Http\Controllers;

use App\Models\Ingredient;
use App\Services\CurrentAppUserResolver;
use Illuminate\Contracts\View\View;

class IngredientController extends Controller
{
    public function index(): View
    {
        return view('ingredients.index');
    }

    public function create(CurrentAppUserResolver $currentAppUserResolver): View
    {
        abort_unless($currentAppUserResolver->resolve() !== null, 404);

        return view('ingredients.editor');
    }

    public function edit(int $ingredient, CurrentAppUserResolver $currentAppUserResolver): View
    {
        $user = $currentAppUserResolver->resolve();
        $ingredient = Ingredient::query()->findOrFail($ingredient);

        abort_unless($user !== null && $ingredient->isOwnedBy($user), 404);

        return view('ingredients.editor', [
            'ingredient' => $ingredient,
        ]);
    }
}
