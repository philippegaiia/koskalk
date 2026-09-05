<?php

namespace App\Http\Controllers;

use App\Enums\MaterialPriceSource;
use App\Models\Ingredient;
use App\Models\User;
use App\Models\Workspace;
use App\Services\CurrentAppUserResolver;
use App\Services\CurrentMaterialPriceService;
use App\Services\IngredientAliasLocaleService;
use App\Services\IngredientCatalogSearchService;
use App\Services\UserIngredientAuthoringService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

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
        $resolvedUser = $currentAppUserResolver->resolve();
        $user = $resolvedUser instanceof User
            ? User::query()->find($resolvedUser->id)
            : null;
        $ingredient = Ingredient::query()->where('public_id', $ingredient)->firstOrFail();

        $isAccessiblePlatformIngredient = $this->isPlatformIngredient($ingredient) && $ingredient->is_active;

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
        $resolvedUser = $request->user();

        if (! $resolvedUser instanceof User) {
            return response()->json(['ok' => false], 403);
        }

        $user = User::query()->find($resolvedUser->id);

        if (! $user instanceof User) {
            return response()->json(['ok' => false], 403);
        }

        try {
            $validated = $request->validate([
                'ingredient_id' => ['required', 'integer', 'exists:ingredients,id'],
                'price_per_kg' => ['required', 'numeric', 'min:0'],
                'destination_workspace_id' => ['present', 'nullable', 'integer'],
                'destination_workspace_signature' => ['required', 'string', 'size:64'],
            ]);
        } catch (ValidationException $exception) {
            if (array_key_exists('destination_workspace_id', $exception->errors())
                || array_key_exists('destination_workspace_signature', $exception->errors())) {
                return response()->json(['ok' => false], 404);
            }

            throw $exception;
        }

        $ingredient = Ingredient::query()->findOrFail($validated['ingredient_id']);
        try {
            $workspace = $this->boundDuplicateDestination($user, $validated);
        } catch (AuthorizationException) {
            return response()->json(['ok' => false], 404);
        }

        abort_unless($workspace instanceof Workspace, 404);

        try {
            if ($this->isPlatformIngredient($ingredient)) {
                if (! $ingredient->is_active) {
                    throw new AuthorizationException;
                }
            } else {
                Gate::forUser($user)->authorize('editWorkspaceIngredient', $ingredient);
            }

            Gate::forUser($user)->authorize('createInWorkspace', [Ingredient::class, $workspace]);
        } catch (AuthorizationException) {
            abort(404);
        }

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
        $authenticatedUser = $request->user();

        if (! $authenticatedUser instanceof User) {
            return response()->json(['ok' => false, 'message' => 'Sign in required.'], 403);
        }

        $user = User::query()->find($authenticatedUser->id);

        if (! $user instanceof User) {
            return response()->json(['ok' => false, 'message' => 'Sign in required.'], 403);
        }

        try {
            $validated = $request->validate([
                'ingredient_id' => ['required', 'integer', 'exists:ingredients,id'],
                'destination_workspace_id' => ['present', 'nullable', 'integer'],
                'destination_workspace_signature' => ['required', 'string', 'size:64'],
            ]);
        } catch (ValidationException $exception) {
            if (array_key_exists('destination_workspace_id', $exception->errors())
                || array_key_exists('destination_workspace_signature', $exception->errors())) {
                return response()->json([
                    'ok' => false,
                    'message' => __('ingredients.editor.validation.stale_workspace'),
                ], 403);
            }

            throw $exception;
        }

        $source = Ingredient::query()->findOrFail($validated['ingredient_id']);

        try {
            $destinationWorkspace = $this->boundDuplicateDestination($user, $validated);

            $copy = app(UserIngredientAuthoringService::class)->duplicateIntoWorkspace(
                $source,
                $user,
                $destinationWorkspace,
            );
        } catch (AuthorizationException) {
            return response()->json([
                'ok' => false,
                'message' => __('ingredients.editor.validation.stale_workspace'),
            ], 403);
        }

        return response()->json([
            'ok' => true,
            'ingredient_id' => $copy->id,
            'redirect' => route('ingredients.edit', $copy),
        ]);
    }

    /**
     * @param  array{destination_workspace_id?: int|null, destination_workspace_signature?: string|null}  $validated
     */
    private function boundDuplicateDestination(User $user, array $validated): ?Workspace
    {
        $destinationWorkspaceId = $validated['destination_workspace_id'] ?? null;
        $signature = $validated['destination_workspace_signature'] ?? null;
        $expectedSignature = hash_hmac(
            'sha256',
            (string) $user->id.'|'.($destinationWorkspaceId ?? 'none'),
            (string) config('app.key'),
        );

        if (! is_string($signature) || ! hash_equals($expectedSignature, $signature)) {
            throw new AuthorizationException;
        }

        if ($destinationWorkspaceId === null) {
            if ($user->active_workspace_id !== null || $user->company() instanceof Workspace) {
                throw new AuthorizationException;
            }

            return null;
        }

        $destinationWorkspace = Workspace::withoutGlobalScopes()->find($destinationWorkspaceId);
        $activeWorkspace = $user->company();

        if (! $destinationWorkspace instanceof Workspace
            || ! $activeWorkspace instanceof Workspace
            || (int) $activeWorkspace->id !== (int) $destinationWorkspace->id
            || ($user->active_workspace_id !== null
                && (int) $user->active_workspace_id !== (int) $destinationWorkspace->id)) {
            throw new AuthorizationException;
        }

        return $destinationWorkspace;
    }

    private function isPlatformIngredient(Ingredient $ingredient): bool
    {
        return $ingredient->owner_type === null
            && $ingredient->owner_id === null
            && $ingredient->workspace_id === null;
    }
}
