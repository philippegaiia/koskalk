<?php

namespace App\Listeners;

use App\Services\WorkspaceProvisioner;
use Filament\Auth\Events\Registered;

class CreateDefaultCompany
{
    public function __construct(private readonly WorkspaceProvisioner $workspaceProvisioner) {}

    public function handle(Registered $event): void
    {
        $this->workspaceProvisioner->ensureOwnerWorkspace($event->user);
    }
}
