<?php

namespace App\Policies\Concerns;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\WorkspaceMemberRole;

trait HandlesWorkspaceAuthorization
{
    protected function canAccessWorkspace(User $user, Workspace $workspace): bool
    {
        return $workspace->owner_user_id === $user->id
            || in_array($workspace->id, $user->accessibleWorkspaceIds(), true);
    }

    protected function canManageWorkspace(User $user, Workspace $workspace): bool
    {
        return $workspace->owner_user_id === $user->id
            || $this->workspaceHasRole($user, $workspace->id, [
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
        $workspace = Workspace::withoutGlobalScopes()->find($workspaceId);

        if ($workspace?->owner_user_id === $user->id) {
            return true;
        }

        $role = WorkspaceMember::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where('user_id', $user->id)
            ->value('role');

        if ($role === null) {
            return false;
        }

        $workspaceRole = $role instanceof WorkspaceMemberRole ? $role : WorkspaceMemberRole::from($role);

        return in_array($workspaceRole, $allowedRoles, true);
    }
}
