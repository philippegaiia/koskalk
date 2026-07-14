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

    public function view(User $user, Recipe $recipe): bool
    {
        return $this->isWorkspaceOwner($user, $recipe);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Recipe $recipe): bool
    {
        return $this->isWorkspaceOwner($user, $recipe);
    }

    public function delete(User $user, Recipe $recipe): bool
    {
        return $this->isWorkspaceOwner($user, $recipe);
    }

    public function restore(User $user, Recipe $recipe): bool
    {
        return $this->update($user, $recipe);
    }

    public function forceDelete(User $user, Recipe $recipe): bool
    {
        return false;
    }

    private function isWorkspaceOwner(User $user, Recipe $recipe): bool
    {
        if ($recipe->workspace_id !== null) {
            return $recipe->workspace()
                ->withoutGlobalScopes()
                ->where('owner_user_id', $user->id)
                ->exists();
        }

        return $recipe->isOwnedBy($user);
    }
}
