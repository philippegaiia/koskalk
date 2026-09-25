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
        return $user->can('view', $recipeVersionCostingPackagingItem->costing);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, RecipeVersionCostingPackagingItem $recipeVersionCostingPackagingItem): bool
    {
        return $user->can('update', $recipeVersionCostingPackagingItem->costing);
    }

    public function delete(User $user, RecipeVersionCostingPackagingItem $recipeVersionCostingPackagingItem): bool
    {
        return $user->can('delete', $recipeVersionCostingPackagingItem->costing);
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
