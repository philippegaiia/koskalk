<?php

namespace App\Policies;

use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\RecipeVersion;
use App\Models\User;

class RecipeItemPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, RecipeItem $recipeItem): bool
    {
        return $this->can($user, 'view', $recipeItem);
    }

    public function create(User $user): bool
    {
        return $user->can('create', Recipe::class);
    }

    public function update(User $user, RecipeItem $recipeItem): bool
    {
        return $this->can($user, 'update', $recipeItem);
    }

    public function delete(User $user, RecipeItem $recipeItem): bool
    {
        return $this->can($user, 'delete', $recipeItem);
    }

    public function restore(User $user, RecipeItem $recipeItem): bool
    {
        return $this->update($user, $recipeItem);
    }

    public function forceDelete(User $user, RecipeItem $recipeItem): bool
    {
        return false;
    }

    private function can(User $user, string $ability, RecipeItem $recipeItem): bool
    {
        $version = $recipeItem->recipeVersion()->withoutGlobalScopes()->first();

        return $version instanceof RecipeVersion
            && $version->workspace_id === $recipeItem->workspace_id
            && $user->can($ability, $version);
    }
}
