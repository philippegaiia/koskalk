<?php

namespace App\Policies;

use App\Enums\WorkspaceMemberRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Policies\Concerns\HandlesWorkspaceAuthorization;
use App\Services\WorkspaceCapabilities;

class WorkspaceMemberPolicy
{
    use HandlesWorkspaceAuthorization;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, WorkspaceMember $workspaceMember): bool
    {
        $workspace = Workspace::withoutGlobalScopes()->find($workspaceMember->workspace_id);

        return $workspace instanceof Workspace && $this->canAccessWorkspace($user, $workspace);
    }

    public function create(User $user, WorkspaceMemberRole $role = WorkspaceMemberRole::Editor): bool
    {
        $workspace = $user->company();

        return $workspace instanceof Workspace
            && $this->canAssignRole($user, $workspace, $role)
            && app(WorkspaceCapabilities::class)->allowsCollaboration($workspace)
            && $this->canManageWorkspace($user, $workspace);
    }

    public function update(User $user, WorkspaceMember $workspaceMember, ?WorkspaceMemberRole $role = null): bool
    {
        $member = WorkspaceMember::withoutGlobalScopes()->find($workspaceMember->id);
        $workspace = $member === null ? null : Workspace::withoutGlobalScopes()->find($member->workspace_id);

        return $workspace instanceof Workspace
            && ($role === null || $this->canAssignRole($user, $workspace, $role))
            && $this->canManageMember($user, $workspaceMember);
    }

    public function acceptInvitation(User $inviter, Workspace $workspace, WorkspaceMemberRole $role): bool
    {
        return app(WorkspaceCapabilities::class)->allowsCollaboration($workspace)
            && in_array($inviter->workspaceRoleFor($workspace->id), [WorkspaceMemberRole::Owner, WorkspaceMemberRole::Admin], true)
            && $this->canAssignRole($inviter, $workspace, $role);
    }

    public function delete(User $user, WorkspaceMember $workspaceMember): bool
    {
        return $this->canManageMember($user, $workspaceMember);
    }

    private function canManageMember(User $user, WorkspaceMember $workspaceMember): bool
    {
        $member = WorkspaceMember::withoutGlobalScopes()->find($workspaceMember->id);
        $workspace = $member === null ? null : Workspace::withoutGlobalScopes()->find($member->workspace_id);

        return $workspace instanceof Workspace
            && $member->user_id !== $workspace->owner_user_id
            && $this->canAssignRole($user, $workspace, $member->role)
            && $this->canManageWorkspace($user, $workspace);
    }

    private function canAssignRole(User $user, Workspace $workspace, WorkspaceMemberRole $role): bool
    {
        return in_array($role, [WorkspaceMemberRole::Editor, WorkspaceMemberRole::Viewer], true)
            || ($role === WorkspaceMemberRole::Admin && $user->workspaceRoleFor($workspace->id) === WorkspaceMemberRole::Owner);
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
