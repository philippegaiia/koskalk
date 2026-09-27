<?php

namespace App\Policies;

use App\Models\PackagingItem;
use App\Models\User;
use App\Models\Workspace;
use App\Policies\Concerns\HandlesWorkspaceAuthorization;

class PackagingItemPolicy
{
    use HandlesWorkspaceAuthorization;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, PackagingItem $packagingItem): bool
    {
        return in_array($packagingItem->workspace_id, $user->accessibleWorkspaceIds(), true);
    }

    public function create(User $user, ?Workspace $workspace = null): bool
    {
        $workspace ??= $user->company(fresh: true);

        return $workspace !== null && $this->canEditWorkspaceRecords($user, $workspace->id);
    }

    public function update(User $user, PackagingItem $packagingItem): bool
    {
        return $this->canEditWorkspaceRecords($user, $packagingItem->workspace_id);
    }

    public function delete(User $user, PackagingItem $packagingItem): bool
    {
        return $this->canDeleteWorkspaceRecords($user, $packagingItem->workspace_id);
    }

    public function restore(User $user, PackagingItem $packagingItem): bool
    {
        return $this->update($user, $packagingItem);
    }

    public function forceDelete(User $user, PackagingItem $packagingItem): bool
    {
        return false;
    }
}
