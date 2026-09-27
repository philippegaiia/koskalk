<?php

namespace App\Http\Controllers;

use App\Models\PackagingItem;
use App\Services\CurrentAppUserResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;

class PackagingItemController extends Controller
{
    public function index(): View
    {
        return view('packaging.index');
    }

    public function create(CurrentAppUserResolver $currentAppUserResolver): View
    {
        $user = $currentAppUserResolver->resolve();
        abort_unless($user !== null, 404);
        Gate::forUser($user)->authorize('create', PackagingItem::class);

        return view('packaging.editor');
    }

    public function edit(string $packagingItem, CurrentAppUserResolver $currentAppUserResolver): View
    {
        $user = $currentAppUserResolver->resolve();
        $packagingItem = PackagingItem::query()->where('public_id', $packagingItem)->firstOrFail();

        abort_unless($user !== null && $user->can('view', $packagingItem), 404);

        return view('packaging.editor', [
            'packagingItem' => $packagingItem,
        ]);
    }
}
