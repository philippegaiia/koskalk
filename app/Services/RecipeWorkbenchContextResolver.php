<?php

namespace App\Services;

use App\Enums\SoapProcess;
use App\Models\ProductFamily;
use App\Models\ProductType;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

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

    /**
     * Families that open in the workbench. A liquid soap paste family opens
     * its own formula view only once it is active.
     */
    public function productFamily(string $slug): ProductFamily
    {
        return ProductFamily::query()
            ->where('slug', $slug)
            ->where(fn (Builder $query): Builder => $query
                ->whereNull('soap_process')
                ->orWhere('soap_process', '!=', SoapProcess::LiquidPaste->value)
                ->orWhere('is_active', true))
            ->firstOrFail();
    }

    public function productType(ProductFamily $productFamily, ?string $slug): ?ProductType
    {
        if ($slug === null || $slug === '') {
            return null;
        }

        return ProductType::query()
            ->whereHas(
                'productFamilies',
                fn (Builder $query): Builder => $query->whereKey($productFamily->id),
            )
            ->where('slug', $slug)
            ->where('is_active', true)
            ->firstOrFail();
    }

    public function currentRecipe(?int $recipeId, ?User $user): ?Recipe
    {
        if ($recipeId === null || ! $user instanceof User) {
            return null;
        }

        $recipe = Recipe::withoutGlobalScopes()
            ->with('mediaAssetUsages')
            ->whereKey($recipeId)
            ->first();

        if (! $recipe instanceof Recipe || ! $user->can('view', $recipe)) {
            return null;
        }

        return $recipe;
    }
}
