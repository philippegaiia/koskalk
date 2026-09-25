<?php

namespace App\Policies\Concerns;

use App\Enums\WorkspaceMemberRole;
use App\Models\User;
use App\Models\Workspace;

trait HandlesWorkspaceAuthorization
{
    protected function canAccessWorkspace(User $user, Workspace $workspace): bool
    {
        return $workspace->roleFor($user) !== null;
    }

    protected function canManageWorkspace(User $user, Workspace $workspace): bool
    {
        return $this->workspaceHasRole($user, $workspace->id, [
            WorkspaceMemberRole::Owner,
            WorkspaceMemberRole::Admin,
        ]);
    }

    protected function canEditWorkspaceRecords(User $user, int $workspaceId): bool
    {
        return $this->workspaceHasRole($user, $workspaceId, [
            WorkspaceMemberRole::Owner,
            WorkspaceMemberRole::Admin,
            WorkspaceMemberRole::Editor,
        ]);
    }

    protected function canDeleteWorkspaceRecords(User $user, int $workspaceId): bool
    {
        return $this->workspaceHasRole($user, $workspaceId, [
            WorkspaceMemberRole::Owner,
            WorkspaceMemberRole::Admin,
        ]);
    }

    protected function workspaceHasRole(User $user, int $workspaceId, array $allowedRoles): bool
    {
        return in_array($user->workspaceRoleFor($workspaceId), $allowedRoles, true);
    }
}
