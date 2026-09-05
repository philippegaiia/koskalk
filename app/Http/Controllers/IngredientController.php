<?php

namespace App\Http\Controllers;

use App\Enums\MaterialPriceSource;
use App\Enums\OwnerType;
use App\Models\Ingredient;
use App\Models\User;
use App\Models\Workspace;
use App\Services\CurrentAppUserResolver;
use App\Services\CurrentMaterialPriceService;
use App\Services\IngredientAliasLocaleService;
use App\Services\IngredientCatalogSearchService;
use App\Services\UserIngredientAuthoringService;
use App\Support\NumberLocale;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
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
            $workspace = $this->boundWorkspaceDestination($user, $validated);
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
        UserIngredientAuthoringService $userIngredientAuthoringService,
    ): JsonResponse {
        $query = (string) $request->query('q', '');
        $translationLocales = Ingredient::translationLocaleCandidates();
        $authenticatedUser = $request->user();
        $user = $authenticatedUser instanceof User
            ? User::query()->find($authenticatedUser->id)
            : null;
        $numberLocale = $user?->number_locale;
        $workspace = $user?->company();
        $destinationDuplicationBlocker = $user instanceof User
            ? $userIngredientAuthoringService->duplicateDestinationBlocker($user, $workspace)
            : __('ingredients.editor.validation.stale_workspace');

        $results = Ingredient::query()
            ->with([
                'translations' => fn ($translationQuery) => $translationQuery
                    ->whereIn('locale', $translationLocales),
                'identifiers',
                'aliases',
                'sapProfile',
                'fattyAcidEntries.fattyAcid',
            ])
            ->where(function (Builder $sourceQuery) use ($user, $workspace): void {
                $sourceQuery->where(function (Builder $platformQuery): void {
                    $platformQuery
                        ->whereNull('owner_type')
                        ->whereNull('owner_id')
                        ->whereNull('workspace_id');
                });

                if (! $user instanceof User) {
                    return;
                }

                $sourceQuery->orWhere(function (Builder $userQuery) use ($user): void {
                    $userQuery
                        ->where('owner_type', OwnerType::User->value)
                        ->where('owner_id', $user->id);
                });

                if ($workspace instanceof Workspace) {
                    $sourceQuery->orWhere(function (Builder $workspaceQuery) use ($workspace): void {
                        $workspaceQuery
                            ->where('owner_type', OwnerType::Workspace->value)
                            ->where('owner_id', $workspace->id);
                    });
                }
            })
            ->where('is_active', true)
            ->when(filled($query), fn ($q) => $catalogSearch->apply($q, $query, $translationLocales))
            ->limit(20)
            ->get()
            ->map(function (Ingredient $ingredient) use (
                $ingredientAliasLocaleService,
                $translationLocales,
                $userIngredientAuthoringService,
                $destinationDuplicationBlocker,
                $numberLocale,
                $user,
                $workspace,
            ): array {
                $duplicationReason = $userIngredientAuthoringService->duplicateSourceBlocker(
                    $ingredient,
                    $user,
                    $workspace,
                )
                    ?? $destinationDuplicationBlocker;
                $chemistry = $userIngredientAuthoringService->duplicationChemistryPreview($ingredient);

                return [
                    'id' => $ingredient->id,
                    'name' => $ingredient->localizedDisplayName(),
                    'inci_name' => $ingredient->inci_name,
                    'category' => $ingredient->category?->getLabel(),
                    'source' => $this->isPlatformIngredient($ingredient) ? 'platform' : 'workspace',
                    'identifiers' => $ingredient->identifiers->map(fn ($identifier): array => [
                        'scheme' => $identifier->scheme->value,
                        'value' => $identifier->value,
                    ])->all(),
                    'aliases' => $ingredientAliasLocaleService
                        ->eligibleAliases($ingredient->aliases, $translationLocales)
                        ->pluck('name')
                        ->all(),
                    'duplication' => [
                        'available' => $duplicationReason === null,
                        'reason' => $duplicationReason,
                        'inherits_soap_chemistry' => $chemistry !== null,
                        'chemistry' => $this->formatDuplicationChemistry($chemistry, $numberLocale),
                    ],
                ];
            })
            ->sortBy('name')
            ->values();

        return response()->json($results);
    }

    /**
     * @param  array<string, mixed>|null  $chemistry
     * @return array<string, mixed>|null
     */
    private function formatDuplicationChemistry(?array $chemistry, ?string $numberLocale): ?array
    {
        if ($chemistry === null) {
            return null;
        }

        $format = fn (mixed $value, int $decimals): string => NumberLocale::formatDecimal(
            $value,
            $decimals,
            $numberLocale,
        );

        return [
            'koh_sap' => [
                'minimum' => $format($chemistry['koh_sap']['minimum'], 6),
                'maximum' => $format($chemistry['koh_sap']['maximum'], 6),
                'original' => $format($chemistry['koh_sap']['original'], 6),
            ],
            'naoh_sap' => [
                'minimum' => $format($chemistry['naoh_sap']['minimum'], 6),
                'maximum' => $format($chemistry['naoh_sap']['maximum'], 6),
                'original' => $format($chemistry['naoh_sap']['original'], 6),
            ],
            'fatty_acid_total' => [
                'minimum' => $format($chemistry['fatty_acid_total']['minimum'], 1),
                'maximum' => $format($chemistry['fatty_acid_total']['maximum'], 1),
            ],
            'fatty_acids' => collect($chemistry['fatty_acids'] ?? [])
                ->take(20)
                ->map(function (array $fattyAcid) use ($format): array {
                    $original = $format($fattyAcid['original'], 1);
                    $minimum = $format($fattyAcid['minimum'], 1);
                    $maximum = $format($fattyAcid['maximum'], 1);
                    $name = (string) $fattyAcid['name'];

                    return [
                        'id' => (int) $fattyAcid['id'],
                        'name' => $name,
                        'original' => $original,
                        'minimum' => $minimum,
                        'maximum' => $maximum,
                        'display' => __('ingredients.duplicate.preview.fatty_acid_range', [
                            'name' => $name,
                            'minimum' => $minimum,
                            'maximum' => $maximum,
                            'original' => $original,
                        ]),
                    ];
                })
                ->values()
                ->all(),
        ];
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
            $destinationWorkspace = $this->boundWorkspaceDestination($user, $validated);

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
    private function boundWorkspaceDestination(User $user, array $validated): ?Workspace
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
