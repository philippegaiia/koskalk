<?php

namespace App\Listeners;

use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceMember;
use App\Services\WorkspaceProvisioner;
use Filament\Auth\Events\Registered;

class CreateDefaultCompany
{
    public function __construct(private readonly WorkspaceProvisioner $workspaceProvisioner) {}

    public function handle(Registered $event): void
    {
        $user = $event->getUser();

        if (WorkspaceMember::withoutGlobalScopes()->where('user_id', $user->id)->exists()
            || WorkspaceInvitation::query()->where('accepted_by_user_id', $user->id)->exists()) {
            return;
        }

        $this->workspaceProvisioner->ensureOwnerWorkspace($user);
    }
}
