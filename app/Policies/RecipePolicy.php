<?php

namespace App\Policies;

use App\Enums\WorkspaceMemberRole;
use App\Enums\WorkspaceModule;
use App\Models\Recipe;
use App\Models\User;
use App\Policies\Concerns\HandlesWorkspaceAuthorization;
use App\Services\WorkspaceAuthorization;

class RecipePolicy
{
    use HandlesWorkspaceAuthorization;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Recipe $recipe): bool
    {
        return $recipe->workspace_id !== null
            ? app(WorkspaceAuthorization::class)->canViewModule($user, $recipe->workspace_id, WorkspaceModule::Formulation)
            : $recipe->isOwnedBy($user);
    }

    public function create(User $user): bool
    {
        $workspace = $user->company();

        return $workspace === null
            ? $user->active_workspace_id === null
            : app(WorkspaceAuthorization::class)->canEditModule($user, $workspace->id, WorkspaceModule::Formulation);
    }

    public function update(User $user, Recipe $recipe): bool
    {
        return $recipe->workspace_id !== null
            ? app(WorkspaceAuthorization::class)->canEditModule($user, $recipe->workspace_id, WorkspaceModule::Formulation)
            : $recipe->isOwnedBy($user);
    }

    public function manageLock(User $user, Recipe $recipe): bool
    {
        if ($recipe->workspace_id !== null) {
            return $this->workspaceHasRole($user, $recipe->workspace_id, [
                WorkspaceMemberRole::Owner,
                WorkspaceMemberRole::Admin,
            ]);
        }

        return $recipe->isOwnedBy($user);
    }

    public function delete(User $user, Recipe $recipe): bool
    {
        return $recipe->workspace_id !== null
            ? $this->canDeleteWorkspaceRecords($user, $recipe->workspace_id)
            : $recipe->isOwnedBy($user);
    }

    public function restore(User $user, Recipe $recipe): bool
    {
        return $this->update($user, $recipe);
    }

    public function forceDelete(User $user, Recipe $recipe): bool
    {
        return false;
    }
}
