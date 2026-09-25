<?php

namespace App\Policies;

use App\Models\RecipeVersionCostingItem;
use App\Models\User;

class RecipeVersionCostingItemPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, RecipeVersionCostingItem $recipeVersionCostingItem): bool
    {
        return $user->can('view', $recipeVersionCostingItem->costing);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, RecipeVersionCostingItem $recipeVersionCostingItem): bool
    {
        return $user->can('update', $recipeVersionCostingItem->costing);
    }

    public function delete(User $user, RecipeVersionCostingItem $recipeVersionCostingItem): bool
    {
        return $user->can('delete', $recipeVersionCostingItem->costing);
    }

    public function restore(User $user, RecipeVersionCostingItem $recipeVersionCostingItem): bool
    {
        return $this->update($user, $recipeVersionCostingItem);
    }

    public function forceDelete(User $user, RecipeVersionCostingItem $recipeVersionCostingItem): bool
    {
        return false;
    }
}
