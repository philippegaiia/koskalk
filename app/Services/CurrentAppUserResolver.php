<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

class CurrentAppUserResolver
{
    public function resolve(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }
}
