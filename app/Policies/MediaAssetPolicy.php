<?php

namespace App\Policies;

use App\Models\MediaAsset;
use App\Models\User;
use App\Policies\Concerns\HandlesWorkspaceAuthorization;

class MediaAssetPolicy
{
    use HandlesWorkspaceAuthorization;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, MediaAsset $mediaAsset): bool
    {
        return $this->canAccessWorkspace($user, $mediaAsset->workspace);
    }

    public function create(User $user): bool
    {
        return true;
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
