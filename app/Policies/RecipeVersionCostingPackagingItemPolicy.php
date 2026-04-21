<?php

namespace App\Policies;

use App\Models\RecipeVersionCostingPackagingItem;
use App\Models\User;

class RecipeVersionCostingPackagingItemPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, RecipeVersionCostingPackagingItem $recipeVersionCostingPackagingItem): bool
    {
        return $recipeVersionCostingPackagingItem->costing->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, RecipeVersionCostingPackagingItem $recipeVersionCostingPackagingItem): bool
    {
        return $this->view($user, $recipeVersionCostingPackagingItem);
    }

    public function delete(User $user, RecipeVersionCostingPackagingItem $recipeVersionCostingPackagingItem): bool
    {
        return $this->view($user, $recipeVersionCostingPackagingItem);
    }

    public function restore(User $user, RecipeVersionCostingPackagingItem $recipeVersionCostingPackagingItem): bool
    {
        return $this->update($user, $recipeVersionCostingPackagingItem);
    }

    public function forceDelete(User $user, RecipeVersionCostingPackagingItem $recipeVersionCostingPackagingItem): bool
    {
        return false;
    }
}
