<?php

namespace App\Actions\Dossiers;

use App\Enums\WorkspaceModule;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceDossierSetting;
use App\Services\Dossiers\DossierSettingsService;
use App\Services\WorkspaceAuthorization;
use Illuminate\Auth\Access\AuthorizationException;

class SaveDossierSettings
{
    public function __construct(
        private readonly WorkspaceAuthorization $authorization,
        private readonly DossierSettingsService $settings,
    ) {}

    /**
     * @param  array<string, mixed>  $data  Already validated input.
     */
    public function handle(User $actor, Workspace $workspace, array $data): WorkspaceDossierSetting
    {
        if (! $this->authorization->canEditModule($actor, $workspace->id, WorkspaceModule::ProductDossiers)) {
            throw new AuthorizationException;
        }

        return $this->settings->save($workspace, $data);
    }
}
