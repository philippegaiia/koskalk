<?php

namespace App\Services;

use App\Enums\ProductionBenchEntitlementStatus;
use App\Enums\WorkspaceMemberRole;
use App\Enums\WorkspaceModule;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceProductionEntitlement;

class WorkspaceAuthorization
{
    public function __construct(private readonly WorkspaceCapabilities $capabilities) {}

    public function role(User $user, int $workspaceId): ?WorkspaceMemberRole
    {
        $workspace = $user->company(fresh: true);

        return $workspace?->id === $workspaceId ? $this->workspaceRole($user, $workspace) : null;
    }

    public function selectedWorkspace(User $user): ?Workspace
    {
        $workspace = $user->company(fresh: true);

        return $workspace !== null && $this->workspaceRole($user, $workspace) !== null ? $workspace : null;
    }

    private function workspaceRole(User $user, Workspace $workspace): ?WorkspaceMemberRole
    {
        if ($workspace->owner_user_id === $user->id) {
            return WorkspaceMemberRole::Owner;
        }

        $role = $user->workspaceRoleFor($workspace->id);

        return $role !== null && $this->capabilities->allowsCollaboration($workspace) ? $role : null;
    }

    public function canView(User $user, int $workspaceId): bool
    {
        return $this->role($user, $workspaceId) !== null;
    }

    public function canEdit(User $user, int $workspaceId): bool
    {
        return in_array($this->role($user, $workspaceId), [
            WorkspaceMemberRole::Owner,
            WorkspaceMemberRole::Admin,
            WorkspaceMemberRole::Editor,
        ], true);
    }

    public function canManage(User $user, int $workspaceId): bool
    {
        return in_array($this->role($user, $workspaceId), [
            WorkspaceMemberRole::Owner,
            WorkspaceMemberRole::Admin,
        ], true);
    }

    public function canViewModule(User $user, int $workspaceId, WorkspaceModule $module): bool
    {
        return $this->canView($user, $workspaceId) && match ($module) {
            WorkspaceModule::Formulation => true,
            WorkspaceModule::ProductionBench => WorkspaceProductionEntitlement::query()
                ->where('workspace_id', $workspaceId)
                ->whereIn('status', [ProductionBenchEntitlementStatus::Active, ProductionBenchEntitlementStatus::Cancelled])
                ->exists(),
        };
    }

    public function canEditModule(User $user, int $workspaceId, WorkspaceModule $module): bool
    {
        return $this->canEdit($user, $workspaceId) && match ($module) {
            WorkspaceModule::Formulation => true,
            WorkspaceModule::ProductionBench => WorkspaceProductionEntitlement::query()
                ->where('workspace_id', $workspaceId)
                ->where('status', ProductionBenchEntitlementStatus::Active)
                ->exists(),
        };
    }
}
