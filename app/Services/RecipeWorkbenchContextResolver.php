<?php

namespace App\Services;

use App\Models\ProductFamily;
use App\Models\Recipe;
use App\Models\User;

class RecipeWorkbenchContextResolver
{
    public function __construct(
        private readonly CurrentAppUserResolver $currentAppUserResolver,
    ) {}

    public function currentUser(?int $actorUserId): ?User
    {
        return $this->currentAppUserResolver->resolve($actorUserId);
    }

    public function soapFamily(): ProductFamily
    {
        return ProductFamily::query()
            ->where('slug', 'soap')
            ->firstOrFail();
    }

    public function currentRecipe(?int $recipeId, ?User $user): ?Recipe
    {
        if ($recipeId === null || ! $user instanceof User) {
            return null;
        }

        $recipe = Recipe::withoutGlobalScopes()
            ->whereKey($recipeId)
            ->first();

        if (! $recipe instanceof Recipe || ! $recipe->isAccessibleBy($user)) {
            return null;
        }

        return $recipe;
    }
}
