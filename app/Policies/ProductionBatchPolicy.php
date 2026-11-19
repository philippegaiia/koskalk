<?php

namespace App\Policies;

use App\Enums\WorkspaceMemberRole;
use App\Models\ProductionBatch;
use App\Models\Recipe;
use App\Models\User;
use App\Services\WorkspaceAuthorization;

class ProductionBatchPolicy
{
    public function __construct(private readonly WorkspaceAuthorization $authorization) {}

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ProductionBatch $productionBatch): bool
    {
        $workspaceId = $productionBatch->workspace_id;
        $role = $workspaceId === null ? null : $this->authorization->role($user, $workspaceId);

        return $this->canViewBatch($user, $productionBatch, $role);
    }

    public function create(User $user): bool
    {
        return $user->can('create', Recipe::class);
    }

    public function update(User $user, ProductionBatch $productionBatch): bool
    {
        return $productionBatch->workspace_id !== null
            ? $this->authorization->canEdit($user, $productionBatch->workspace_id)
            : $productionBatch->user_id === $user->id;
    }

    public function delete(User $user, ProductionBatch $productionBatch): bool
    {
        $workspaceId = $productionBatch->workspace_id;
        $role = $workspaceId === null ? null : $this->authorization->role($user, $workspaceId);

        return $this->canDeleteBatch($user, $productionBatch, $role);
    }

    /**
     * @param  iterable<ProductionBatch>  $batches
     * @return array<int, bool> Delete permission keyed by visible batch ID.
     */
    public function historyPermissions(User $user, iterable $batches): array
    {
        $roles = [];
        $permissions = [];

        foreach ($batches as $batch) {
            $workspaceId = $batch->workspace_id;
            if ($workspaceId !== null && ! array_key_exists($workspaceId, $roles)) {
                $roles[$workspaceId] = $this->authorization->role($user, $workspaceId);
            }

            $role = $workspaceId === null ? null : $roles[$workspaceId];
            if ($this->canViewBatch($user, $batch, $role)) {
                $permissions[$batch->id] = $this->canDeleteBatch($user, $batch, $role);
            }
        }

        return $permissions;
    }

    private function canViewBatch(User $user, ProductionBatch $batch, ?WorkspaceMemberRole $role): bool
    {
        return $batch->workspace_id === null ? $batch->user_id === $user->id : $role !== null;
    }

    private function canDeleteBatch(User $user, ProductionBatch $batch, ?WorkspaceMemberRole $role): bool
    {
        if ($batch->workspace_id === null) {
            return $batch->user_id === $user->id;
        }

        return in_array($role, [WorkspaceMemberRole::Owner, WorkspaceMemberRole::Admin], true)
            || ($role === WorkspaceMemberRole::Editor && $batch->user_id === $user->id);
    }

    public function restore(User $user, ProductionBatch $productionBatch): bool
    {
        return false;
    }

    public function forceDelete(User $user, ProductionBatch $productionBatch): bool
    {
        return false;
    }
}
