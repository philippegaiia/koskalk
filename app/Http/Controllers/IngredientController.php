<?php

namespace App\Http\Controllers;

use App\Models\Ingredient;
use App\Models\User;
use App\Services\CurrentAppUserResolver;
use App\Services\UserIngredientAuthoringService;
use App\Services\UserIngredientPriceMemory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
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

    public function updatePrice(Request $request, UserIngredientPriceMemory $priceMemory): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json(['ok' => false], 403);
        }

        $validated = $request->validate([
            'ingredient_id' => ['required', 'integer', 'exists:ingredients,id'],
            'price_per_kg' => ['required', 'numeric', 'min:0'],
        ]);

        $ingredient = Ingredient::query()->findOrFail($validated['ingredient_id']);

        abort_unless($this->canUpdatePrice($ingredient, $user), 404);

        $priceMemory->remember($user, $ingredient->id, (float) $validated['price_per_kg']);

        return response()->json(['ok' => true]);
    }

    public function searchPlatform(Request $request)
    {
        $query = (string) $request->query('q', '');

        $results = Ingredient::query()
            ->whereNull('owner_type')
            ->where('is_active', true)
            ->when(filled($query), fn ($q) => $q->where(function ($q) use ($query) {
                $lower = mb_strtolower($query);
                $q->whereRaw('LOWER(display_name) LIKE ?', ["%{$lower}%"])
                    ->orWhereRaw('LOWER(inci_name) LIKE ?', ["%{$lower}%"]);
            }))
            ->orderBy('display_name')
            ->limit(20)
            ->get()
            ->map(fn (Ingredient $ingredient) => [
                'id' => $ingredient->id,
                'name' => $ingredient->display_name,
                'inci_name' => $ingredient->inci_name,
                'category' => $ingredient->category?->getLabel(),
            ]);

        return response()->json($results);
    }

    public function duplicate(Request $request)
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json(['ok' => false, 'message' => 'Sign in required.'], 403);
        }

        $validated = $request->validate([
            'ingredient_id' => ['required', 'integer', 'exists:ingredients,id'],
        ]);

        $source = Ingredient::query()->findOrFail($validated['ingredient_id']);

        $copy = app(UserIngredientAuthoringService::class)->duplicate($source, $user);

        return response()->json([
            'ok' => true,
            'ingredient_id' => $copy->id,
            'redirect' => route('ingredients.edit', $copy->id),
        ]);
    }

    private function canUpdatePrice(Ingredient $ingredient, User $user): bool
    {
        if ($ingredient->owner_type === null) {
            return $ingredient->is_active;
        }

        return $ingredient->isOwnedBy($user) || $ingredient->isWorkspaceAccessibleBy($user);
    }
}
