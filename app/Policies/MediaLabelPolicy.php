<?php

namespace App\Policies;

use App\Models\MediaLabel;
use App\Models\User;
use App\Models\Workspace;
use App\Policies\Concerns\HandlesWorkspaceAuthorization;
use App\Services\WorkspaceAuthorization;

class MediaLabelPolicy
{
    use HandlesWorkspaceAuthorization;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, MediaLabel $mediaLabel): bool
    {
        return app(WorkspaceAuthorization::class)->canView($user, $mediaLabel->workspace_id);
    }

    public function create(User $user, Workspace $workspace): bool
    {
        return $this->canEditWorkspaceRecords($user, $workspace->id);
    }

    public function update(User $user, MediaLabel $mediaLabel): bool
    {
        return $this->canEditWorkspaceRecords($user, $mediaLabel->workspace_id);
    }

    public function delete(User $user, MediaLabel $mediaLabel): bool
    {
        return $this->canDeleteWorkspaceRecords($user, $mediaLabel->workspace_id);
    }
}
