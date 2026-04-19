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
        return $recipeVersionCostingItem->costing->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, RecipeVersionCostingItem $recipeVersionCostingItem): bool
    {
        return $this->view($user, $recipeVersionCostingItem);
    }

    public function delete(User $user, RecipeVersionCostingItem $recipeVersionCostingItem): bool
    {
        return $this->view($user, $recipeVersionCostingItem);
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
