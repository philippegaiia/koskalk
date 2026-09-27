<?php

namespace App\Policies;

use App\Enums\WorkspaceMemberRole;
use App\Models\User;
use App\Models\Workspace;
use App\Policies\Concerns\HandlesWorkspaceAuthorization;
use App\Services\WorkspaceSelectionService;
use Illuminate\Auth\Access\Response;

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

    public function select(User $user, Workspace $workspace): Response
    {
        return app(WorkspaceSelectionService::class)->canSelect($user, $workspace)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Workspace $workspace): bool
    {
        return $this->canManageWorkspace($user, $workspace);
    }

    public function delete(User $user, Workspace $workspace): bool
    {
        return $user->workspaceRoleFor($workspace->id) === WorkspaceMemberRole::Owner
            && $this->canAccessWorkspace($user, $workspace);
    }

    public function restore(User $user, Workspace $workspace): bool
    {
        return $user->workspaceRoleFor($workspace->id) === WorkspaceMemberRole::Owner
            && $this->canAccessWorkspace($user, $workspace);
    }

    public function forceDelete(User $user, Workspace $workspace): bool
    {
        return false;
    }
}
