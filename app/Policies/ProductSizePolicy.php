<?php

namespace App\Policies;

use App\Models\ProductSize;
use App\Models\Recipe;
use App\Models\User;

/**
 * A Product size has no access rules of its own: it follows its Product.
 */
class ProductSizePolicy
{
    public function view(User $user, ProductSize $productSize): bool
    {
        return $this->can($user, 'view', $productSize);
    }

    public function create(User $user, Recipe $recipe): bool
    {
        return $user->can('update', $recipe);
    }

    public function update(User $user, ProductSize $productSize): bool
    {
        return $this->can($user, 'update', $productSize);
    }

    public function delete(User $user, ProductSize $productSize): bool
    {
        return $this->can($user, 'update', $productSize);
    }

    /**
     * Load the Product without the session-dependent tenant scope, then let
     * RecipePolicy decide for the user being authorized.
     */
    private function can(User $user, string $ability, ProductSize $productSize): bool
    {
        $recipe = $productSize->recipe()
            ->withoutGlobalScopes()
            ->first();

        return $recipe instanceof Recipe && $user->can($ability, $recipe);
    }
}
