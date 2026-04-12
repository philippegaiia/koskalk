<?php

namespace App\Http\Controllers;

use App\Models\Ingredient;
use App\Models\User;
use App\Models\UserIngredientPrice;
use App\Services\CurrentAppUserResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

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

    public function updatePrice(Request $request)
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json(['ok' => false], 403);
        }

        $validated = $request->validate([
            'ingredient_id' => ['required', 'integer', 'exists:ingredients,id'],
            'price_per_kg' => ['required', 'numeric', 'min:0'],
        ]);

        UserIngredientPrice::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'ingredient_id' => $validated['ingredient_id'],
            ],
            [
                'price_per_kg' => round((float) $validated['price_per_kg'], 4),
                'currency' => 'EUR',
                'last_used_at' => now(),
            ],
        );

        return response()->json(['ok' => true]);
    }
}
