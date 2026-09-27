<?php

namespace App\Policies;

use App\Models\Recipe;
use App\Models\RecipePhase;
use App\Models\RecipeVersion;
use App\Models\User;

class RecipePhasePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, RecipePhase $recipePhase): bool
    {
        return $this->can($user, 'view', $recipePhase);
    }

    public function create(User $user): bool
    {
        return $user->can('create', Recipe::class);
    }

    public function update(User $user, RecipePhase $recipePhase): bool
    {
        return $this->can($user, 'update', $recipePhase);
    }

    public function delete(User $user, RecipePhase $recipePhase): bool
    {
        return $this->can($user, 'delete', $recipePhase);
    }

    public function restore(User $user, RecipePhase $recipePhase): bool
    {
        return $this->update($user, $recipePhase);
    }

    public function forceDelete(User $user, RecipePhase $recipePhase): bool
    {
        return false;
    }

    private function can(User $user, string $ability, RecipePhase $recipePhase): bool
    {
        $version = $recipePhase->recipeVersion()->withoutGlobalScopes()->first();

        return $version instanceof RecipeVersion
            && $version->workspace_id === $recipePhase->workspace_id
            && $user->can($ability, $version);
    }
}
