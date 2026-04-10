<?php

namespace App\Http\Controllers;

use App\Models\UserPackagingItem;
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

    public function edit(int $packagingItem, CurrentAppUserResolver $currentAppUserResolver): View
    {
        $user = $currentAppUserResolver->resolve();
        $packagingItem = UserPackagingItem::query()->findOrFail($packagingItem);

        abort_unless($user !== null && $packagingItem->user_id === $user->id, 404);

        return view('packaging.editor', [
            'packagingItem' => $packagingItem,
        ]);
    }
}
