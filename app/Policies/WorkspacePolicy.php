<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Workspace;
use App\Policies\Concerns\HandlesWorkspaceAuthorization;

class WorkspacePolicy
{
    use HandlesWorkspaceAuthorization;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Workspace $workspace): bool
    {
        return $this->canAccessWorkspace($user, $workspace);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Workspace $workspace): bool
    {
        return $this->canManageWorkspace($user, $workspace);
    }

    public function delete(User $user, Workspace $workspace): bool
    {
        return $workspace->owner_user_id === $user->id;
    }

    public function restore(User $user, Workspace $workspace): bool
    {
        return $workspace->owner_user_id === $user->id;
    }

    public function forceDelete(User $user, Workspace $workspace): bool
    {
        return false;
    }
}
