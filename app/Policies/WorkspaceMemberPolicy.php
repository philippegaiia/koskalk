<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WorkspaceMember;
use App\Policies\Concerns\HandlesWorkspaceAuthorization;

class WorkspaceMemberPolicy
{
    use HandlesWorkspaceAuthorization;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, WorkspaceMember $workspaceMember): bool
    {
        return $this->canAccessWorkspace($user, $workspaceMember->workspace);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, WorkspaceMember $workspaceMember): bool
    {
        return $this->canManageWorkspace($user, $workspaceMember->workspace);
    }

    public function delete(User $user, WorkspaceMember $workspaceMember): bool
    {
        return $this->canManageWorkspace($user, $workspaceMember->workspace);
    }

    public function restore(User $user, WorkspaceMember $workspaceMember): bool
    {
        return false;
    }

    public function forceDelete(User $user, WorkspaceMember $workspaceMember): bool
    {
        return false;
    }
}
