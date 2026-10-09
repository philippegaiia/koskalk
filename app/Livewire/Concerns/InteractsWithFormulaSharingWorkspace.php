<?php

namespace App\Livewire\Concerns;

use App\Models\User;
use App\Models\Workspace;
use App\Services\WorkspaceAuthorization;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;

trait InteractsWithFormulaSharingWorkspace
{
    #[Locked]
    public int $workspaceId;

    public function hydrate(): void
    {
        $this->workspace();
    }

    private function user(): User
    {
        $id = auth()->id();
        abort_unless($id !== null, 403);

        return User::withoutGlobalScopes()->findOrFail($id);
    }

    private function workspace(): Workspace
    {
        abort_unless(config('workspaces.formula_sharing.enabled', false), isset($this->workspaceId) ? 403 : 404);
        $actor = $this->user();
        $workspace = app(WorkspaceAuthorization::class)->selectedWorkspace($actor);
        abort_unless($workspace !== null, 403);
        abort_if(isset($this->workspaceId) && $workspace->id !== $this->workspaceId, 403);
        Gate::forUser($actor)->authorize('update', $workspace);

        return $workspace;
    }
}
