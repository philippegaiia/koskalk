<?php

namespace App\Policies;

use App\Models\RecipeItem;
use App\Models\User;
use App\Policies\Concerns\HandlesWorkspaceAuthorization;

class RecipeItemPolicy
{
    use HandlesWorkspaceAuthorization;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, RecipeItem $recipeItem): bool
    {
        return $recipeItem->isAccessibleBy($user);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, RecipeItem $recipeItem): bool
    {
        if ($recipeItem->isOwnedBy($user)) {
            return true;
        }

        return $recipeItem->tenantWorkspaceId() !== null
            && $this->canEditWorkspaceRecords($user, $recipeItem->tenantWorkspaceId());
    }

    public function delete(User $user, RecipeItem $recipeItem): bool
    {
        if ($recipeItem->isOwnedBy($user)) {
            return true;
        }

        return $recipeItem->tenantWorkspaceId() !== null
            && $this->canDeleteWorkspaceRecords($user, $recipeItem->tenantWorkspaceId());
    }

    public function restore(User $user, RecipeItem $recipeItem): bool
    {
        return $this->update($user, $recipeItem);
    }

    public function forceDelete(User $user, RecipeItem $recipeItem): bool
    {
        return false;
    }
}
