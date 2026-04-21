<?php

namespace App\Policies;

use App\Models\Recipe;
use App\Models\User;
use App\Policies\Concerns\HandlesWorkspaceAuthorization;

class RecipePolicy
{
    use HandlesWorkspaceAuthorization;

    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Private recipes are visible to all company members but only
     * editable/deletable by the author. is_private controls write
     * access, not read access.
     */
    public function view(User $user, Recipe $recipe): bool
    {
        return $recipe->isAccessibleBy($user);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Recipe $recipe): bool
    {
        if ($recipe->is_private && $recipe->created_by !== $user->id) {
            return false;
        }

        if ($recipe->isOwnedBy($user)) {
            return true;
        }

        return $recipe->tenantWorkspaceId() !== null
            && $this->canEditWorkspaceRecords($user, $recipe->tenantWorkspaceId());
    }

    public function delete(User $user, Recipe $recipe): bool
    {
        if ($recipe->is_private && $recipe->created_by !== $user->id) {
            return false;
        }

        if ($recipe->isOwnedBy($user)) {
            return true;
        }

        return $recipe->tenantWorkspaceId() !== null
            && $this->canDeleteWorkspaceRecords($user, $recipe->tenantWorkspaceId());
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
