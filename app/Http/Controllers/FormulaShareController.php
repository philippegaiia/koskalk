<?php

namespace App\Http\Controllers;

use App\Models\FormulaShare;
use App\Models\Recipe;
use App\Services\CurrentAppUserResolver;
use App\Services\WorkspaceAuthorization;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;

class FormulaShareController extends Controller
{
    public function index(CurrentAppUserResolver $users, WorkspaceAuthorization $authorization): View
    {
        abort_unless(config('workspaces.formula_sharing.enabled', false), 404);
        $user = $users->resolve();
        $workspace = $user === null ? null : $authorization->selectedWorkspace($user->fresh());
        abort_unless($workspace !== null, 404);
        Gate::forUser($user)->authorize('update', $workspace);

        return view('formula-shares.index');
    }

    public function create(string $recipe, CurrentAppUserResolver $users): View
    {
        abort_unless(config('workspaces.formula_sharing.enabled', false), 404);
        $product = Recipe::withoutGlobalScopes()->where('public_id', $recipe)->firstOrFail();
        $actor = $users->resolve();
        abort_unless($actor !== null && $actor->can('view', $product), 404);
        Gate::forUser($actor)->authorize('share', $product);

        return view('formula-shares.create', ['recipe' => $product]);
    }

    public function show(string $share, CurrentAppUserResolver $users): View
    {
        abort_unless(config('workspaces.formula_sharing.enabled', false), 404);
        $offer = FormulaShare::query()->where('public_id', $share)->firstOrFail();
        $actor = $users->resolve();
        abort_unless($actor !== null && $actor->can('view', $offer), 404);
        Gate::forUser($actor)->authorize('view', $offer);

        return view('formula-shares.show', ['share' => $offer]);
    }
}
