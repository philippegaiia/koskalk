<?php

namespace App\Policies;

use App\Models\RecipeVersion;
use App\Models\User;
use App\Policies\Concerns\HandlesWorkspaceAuthorization;

class RecipeVersionPolicy
{
    use HandlesWorkspaceAuthorization;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, RecipeVersion $recipeVersion): bool
    {
        return $recipeVersion->isAccessibleBy($user);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, RecipeVersion $recipeVersion): bool
    {
        if ($recipeVersion->isOwnedBy($user)) {
            return true;
        }

        return $recipeVersion->tenantWorkspaceId() !== null
            && $this->canEditWorkspaceRecords($user, $recipeVersion->tenantWorkspaceId());
    }

    public function delete(User $user, RecipeVersion $recipeVersion): bool
    {
        if ($recipeVersion->isOwnedBy($user)) {
            return true;
        }

        return $recipeVersion->tenantWorkspaceId() !== null
            && $this->canDeleteWorkspaceRecords($user, $recipeVersion->tenantWorkspaceId());
    }

    public function restore(User $user, RecipeVersion $recipeVersion): bool
    {
        return $this->update($user, $recipeVersion);
    }

    public function forceDelete(User $user, RecipeVersion $recipeVersion): bool
    {
        return false;
    }
}
