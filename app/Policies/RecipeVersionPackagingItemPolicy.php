<?php

namespace App\Policies;

use App\Models\RecipeVersionPackagingItem;
use App\Models\User;

class RecipeVersionPackagingItemPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, RecipeVersionPackagingItem $recipeVersionPackagingItem): bool
    {
        return $recipeVersionPackagingItem->recipeVersion->owner_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, RecipeVersionPackagingItem $recipeVersionPackagingItem): bool
    {
        return $this->view($user, $recipeVersionPackagingItem);
    }

    public function delete(User $user, RecipeVersionPackagingItem $recipeVersionPackagingItem): bool
    {
        return $this->view($user, $recipeVersionPackagingItem);
    }

    public function restore(User $user, RecipeVersionPackagingItem $recipeVersionPackagingItem): bool
    {
        return $this->update($user, $recipeVersionPackagingItem);
    }

    public function forceDelete(User $user, RecipeVersionPackagingItem $recipeVersionPackagingItem): bool
    {
        return false;
    }
}
