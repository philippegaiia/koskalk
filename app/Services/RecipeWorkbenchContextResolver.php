<?php

namespace App\Services;

use App\Models\ProductFamily;
use App\Models\ProductType;
use App\Models\Recipe;
use App\Models\User;

class RecipeWorkbenchContextResolver
{
    public function __construct(
        private readonly CurrentAppUserResolver $currentAppUserResolver,
    ) {}

    public function currentUser(): ?User
    {
        return $this->currentAppUserResolver->resolve();
    }

    public function soapFamily(): ProductFamily
    {
        return $this->productFamily('soap');
    }

    public function productFamily(string $slug): ProductFamily
    {
        return ProductFamily::query()
            ->where('slug', $slug)
            ->firstOrFail();
    }

    public function productType(ProductFamily $productFamily, ?string $slug): ?ProductType
    {
        if ($slug === null || $slug === '') {
            return null;
        }

        return ProductType::query()
            ->whereBelongsTo($productFamily)
            ->where('slug', $slug)
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

        if (! $recipe instanceof Recipe || ! $user->can('view', $recipe)) {
            return null;
        }

        return $recipe;
    }
}
