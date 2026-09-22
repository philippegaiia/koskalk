<?php

namespace App\Policies;

use App\Models\HelpContentExport;
use App\Models\User;

class HelpContentExportPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_admin;
    }

    public function create(User $user): bool
    {
        return $user->is_admin;
    }

    public function view(User $user, HelpContentExport $helpContentExport): bool
    {
        return $user->is_admin;
    }

    public function delete(User $user, HelpContentExport $helpContentExport): bool
    {
        return false;
    }

    public function download(User $user, HelpContentExport $helpContentExport): bool
    {
        return $user->is_admin;
    }
}
