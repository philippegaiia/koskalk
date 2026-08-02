<?php

namespace App\Http\Controllers;

use App\Models\PackagingItem;
use App\Services\CurrentAppUserResolver;
use Illuminate\Contracts\View\View;

class PackagingItemController extends Controller
{
    public function index(): View
    {
        return view('packaging.index');
    }

    public function create(CurrentAppUserResolver $currentAppUserResolver): View
    {
        abort_unless($currentAppUserResolver->resolve() !== null, 404);

        return view('packaging.editor');
    }

    public function edit(string $packagingItem, CurrentAppUserResolver $currentAppUserResolver): View
    {
        $user = $currentAppUserResolver->resolve();
        $packagingItem = PackagingItem::query()->where('public_id', $packagingItem)->firstOrFail();

        abort_unless($user !== null && $packagingItem->workspace->hasMember($user), 404);

        return view('packaging.editor', [
            'packagingItem' => $packagingItem,
        ]);
    }
}
