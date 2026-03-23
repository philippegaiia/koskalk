<?php

namespace App\Policies;

use App\Models\RecipePhase;
use App\Models\User;
use App\Policies\Concerns\HandlesWorkspaceAuthorization;

class RecipePhasePolicy
{
    use HandlesWorkspaceAuthorization;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, RecipePhase $recipePhase): bool
    {
        return $recipePhase->isAccessibleBy($user);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, RecipePhase $recipePhase): bool
    {
        if ($recipePhase->isOwnedBy($user)) {
            return true;
        }

        return $recipePhase->tenantWorkspaceId() !== null
            && $this->canEditWorkspaceRecords($user, $recipePhase->tenantWorkspaceId());
    }

    public function delete(User $user, RecipePhase $recipePhase): bool
    {
        if ($recipePhase->isOwnedBy($user)) {
            return true;
        }

        return $recipePhase->tenantWorkspaceId() !== null
            && $this->canDeleteWorkspaceRecords($user, $recipePhase->tenantWorkspaceId());
    }

    public function restore(User $user, RecipePhase $recipePhase): bool
    {
        return $this->update($user, $recipePhase);
    }

    public function forceDelete(User $user, RecipePhase $recipePhase): bool
    {
        return false;
    }
}
