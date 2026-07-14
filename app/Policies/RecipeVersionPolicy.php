<?php

namespace App\Policies;

use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;

class RecipeVersionPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, RecipeVersion $recipeVersion): bool
    {
        return $this->can($user, 'view', $recipeVersion);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, RecipeVersion $recipeVersion): bool
    {
        return $this->can($user, 'update', $recipeVersion);
    }

    public function delete(User $user, RecipeVersion $recipeVersion): bool
    {
        return $this->can($user, 'delete', $recipeVersion);
    }

    public function restore(User $user, RecipeVersion $recipeVersion): bool
    {
        return $this->update($user, $recipeVersion);
    }

    public function forceDelete(User $user, RecipeVersion $recipeVersion): bool
    {
        return false;
    }

    private function can(User $user, string $ability, RecipeVersion $recipeVersion): bool
    {
        $recipe = $recipeVersion->recipe()
            ->withoutGlobalScopes()
            ->first();

        return $recipe instanceof Recipe && $user->can($ability, $recipe);
    }
}
