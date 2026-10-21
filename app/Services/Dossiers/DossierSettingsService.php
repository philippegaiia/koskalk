<?php

namespace App\Services\Dossiers;

use App\Models\Workspace;
use App\Models\WorkspaceDossierSetting;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class DossierSettingsService
{
    public function forWorkspace(Workspace $workspace): ?WorkspaceDossierSetting
    {
        return WorkspaceDossierSetting::query()->where('workspace_id', $workspace->id)->first();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function save(Workspace $workspace, array $data): WorkspaceDossierSetting
    {
        return DB::transaction(function () use ($workspace, $data): WorkspaceDossierSetting {
            Workspace::withoutGlobalScopes()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();

            return WorkspaceDossierSetting::query()->updateOrCreate(
                ['workspace_id' => $workspace->id],
                Arr::only($data, WorkspaceDossierSetting::EDITABLE),
            );
        }, attempts: 5);
    }
}
