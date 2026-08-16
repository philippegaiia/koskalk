<?php

namespace App\Http\Controllers;

use App\Enums\MaterialPriceSource;
use App\Models\Ingredient;
use App\Models\User;
use App\Services\CurrentAppUserResolver;
use App\Services\CurrentMaterialPriceService;
use App\Services\IngredientAliasLocaleService;
use App\Services\IngredientCatalogSearchService;
use App\Services\UserIngredientAuthoringService;
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

    public function edit(string $ingredient, CurrentAppUserResolver $currentAppUserResolver): View
    {
        $user = $currentAppUserResolver->resolve();
        $ingredient = Ingredient::query()->where('public_id', $ingredient)->firstOrFail();

        $isAccessiblePlatformIngredient = $ingredient->owner_type === null && $ingredient->is_active;

        abort_unless(
            $user !== null && ($ingredient->isAccessibleBy($user) || $isAccessiblePlatformIngredient),
            404,
        );

        return view('ingredients.editor', [
            'ingredient' => $ingredient,
        ]);
    }

    public function updatePrice(Request $request, CurrentMaterialPriceService $currentMaterialPriceService): JsonResponse
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
        $workspace = $user->company();

        abort_unless($workspace !== null && $this->canUpdatePrice($ingredient, $user), 404);

        $currentMaterialPriceService->rememberIngredient(
            workspace: $workspace,
            ingredient: $ingredient,
            pricePerMassUnit: (string) $validated['price_per_kg'],
            massUnit: 'kg',
            currency: $user->defaultCurrency(),
            source: MaterialPriceSource::ManualCosting,
            sourceId: null,
            actor: $user,
        );

        return response()->json(['ok' => true]);
    }

    public function searchPlatform(
        Request $request,
        IngredientCatalogSearchService $catalogSearch,
        IngredientAliasLocaleService $ingredientAliasLocaleService,
    ): JsonResponse {
        $query = (string) $request->query('q', '');
        $translationLocales = Ingredient::translationLocaleCandidates();

        $results = Ingredient::query()
            ->with([
                'translations' => fn ($translationQuery) => $translationQuery
                    ->whereIn('locale', $translationLocales),
                'identifiers',
                'aliases',
            ])
            ->whereNull('owner_type')
            ->where('is_active', true)
            ->when(filled($query), fn ($q) => $catalogSearch->apply($q, $query, $translationLocales))
            ->limit(20)
            ->get()
            ->map(fn (Ingredient $ingredient) => [
                'id' => $ingredient->id,
                'name' => $ingredient->localizedDisplayName(),
                'inci_name' => $ingredient->inci_name,
                'category' => $ingredient->category?->getLabel(),
                'identifiers' => $ingredient->identifiers->map(fn ($identifier): array => [
                    'scheme' => $identifier->scheme->value,
                    'value' => $identifier->value,
                ])->all(),
                'aliases' => $ingredientAliasLocaleService
                    ->eligibleAliases($ingredient->aliases, $translationLocales)
                    ->pluck('name')
                    ->all(),
            ])
            ->sortBy('name')
            ->values();

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
            'redirect' => route('ingredients.edit', $copy),
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
