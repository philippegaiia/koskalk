<?php

namespace App\Policies;

use App\Models\MediaAsset;
use App\Models\User;
use App\Policies\Concerns\HandlesWorkspaceAuthorization;
use App\Services\WorkspaceAuthorization;

class MediaAssetPolicy
{
    use HandlesWorkspaceAuthorization;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, MediaAsset $mediaAsset): bool
    {
        return app(WorkspaceAuthorization::class)->canView($user, $mediaAsset->workspace_id);
    }

    public function create(User $user): bool
    {
        $workspace = $user->company();

        return $workspace !== null && $this->canEditWorkspaceRecords($user, $workspace->id);
    }

    public function update(User $user, MediaAsset $mediaAsset): bool
    {
        return $this->canEditWorkspaceRecords($user, $mediaAsset->workspace_id);
    }

    public function delete(User $user, MediaAsset $mediaAsset): bool
    {
        return $this->canDeleteWorkspaceRecords($user, $mediaAsset->workspace_id);
    }

    public function restore(User $user, MediaAsset $mediaAsset): bool
    {
        return false;
    }

    public function forceDelete(User $user, MediaAsset $mediaAsset): bool
    {
        return false;
    }
}
