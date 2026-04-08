<?php

namespace App\Livewire\Dashboard;

use App\Models\User;
use App\Models\UserPackagingItem;
use App\Services\CurrentAppUserResolver;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class PackagingItemsIndex extends Component
{
    public function render(): View
    {
        $currentUser = app(CurrentAppUserResolver::class)->resolve();
        $packagingItems = collect();

        if ($currentUser instanceof User) {
            $packagingItems = UserPackagingItem::query()
                ->where('user_id', $currentUser->id)
                ->orderBy('name')
                ->orderBy('id')
                ->get();
        }

        return view('livewire.dashboard.packaging-items-index', [
            'currentUser' => $currentUser,
            'packagingItems' => $packagingItems,
            'packagingItemCount' => $packagingItems->count(),
        ]);
    }
}
