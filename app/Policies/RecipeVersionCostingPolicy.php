<?php

namespace App\Policies;

use App\Models\RecipeVersionCosting;
use App\Models\User;

class RecipeVersionCostingPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, RecipeVersionCosting $recipeVersionCosting): bool
    {
        return $recipeVersionCosting->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, RecipeVersionCosting $recipeVersionCosting): bool
    {
        return $this->view($user, $recipeVersionCosting);
    }

    public function delete(User $user, RecipeVersionCosting $recipeVersionCosting): bool
    {
        return $this->view($user, $recipeVersionCosting);
    }

    public function restore(User $user, RecipeVersionCosting $recipeVersionCosting): bool
    {
        return $this->update($user, $recipeVersionCosting);
    }

    public function forceDelete(User $user, RecipeVersionCosting $recipeVersionCosting): bool
    {
        return false;
    }
}
