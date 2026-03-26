<?php

namespace App\Services;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;

class CurrentAppUserResolver
{
    public function resolve(?int $fallbackUserId = null): ?User
    {
        $resolvedUser = Auth::user();

        if (! $resolvedUser instanceof User) {
            $filamentUser = Filament::auth()->user();

            if ($filamentUser instanceof User) {
                $resolvedUser = $filamentUser;
            }
        }

        if (! $resolvedUser instanceof User) {
            $adminPanelUser = Filament::getPanel('admin', isStrict: false)?->auth()->user();

            if ($adminPanelUser instanceof User) {
                $resolvedUser = $adminPanelUser;
            }
        }

        if (! $resolvedUser instanceof User && $fallbackUserId !== null) {
            $resolvedUser = User::query()->find($fallbackUserId);
        }

        $currentUser = Auth::user();

        if ($resolvedUser instanceof User && (! $currentUser instanceof User || $currentUser->id !== $resolvedUser->id)) {
            Auth::setUser($resolvedUser);
        }

        return $resolvedUser;
    }
}
