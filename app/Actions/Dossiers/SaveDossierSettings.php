<?php

namespace App\Actions\Dossiers;

use App\Enums\WorkspaceModule;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceDossierSetting;
use App\Services\Dossiers\DossierSettingsService;
use App\Services\WorkspaceAuthorization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class SaveDossierSettings
{
    public function __construct(
        private readonly WorkspaceAuthorization $authorization,
        private readonly DossierSettingsService $settings,
    ) {}

    /**
     * Access is checked after the workspace row is locked, so a membership removal or role change that holds
     * the same lock cannot be overtaken by this write.
     *
     * @param  array<string, mixed>  $data  Already validated input.
     */
    public function handle(User $actor, Workspace $workspace, array $data): WorkspaceDossierSetting
    {
        return DB::transaction(function () use ($actor, $workspace, $data): WorkspaceDossierSetting {
            Workspace::withoutGlobalScopes()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();

            $freshActor = $actor->fresh();

            if (! $freshActor instanceof User
                || ! $this->authorization->canEditModule($freshActor, $workspace->id, WorkspaceModule::ProductDossiers)) {
                throw new AuthorizationException;
            }

            return $this->settings->save($workspace, $data);
        }, attempts: 5);
    }
}
