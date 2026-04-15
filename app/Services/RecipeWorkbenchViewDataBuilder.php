<?php

namespace App\Services;

use App\Models\ProductFamily;
use App\Models\ProductType;
use App\Models\Recipe;
use App\Models\User;

class RecipeWorkbenchViewDataBuilder
{
    public function __construct(
        private readonly RecipeWorkbenchService $recipeWorkbenchService,
        private readonly RecipeWorkbenchIngredientCatalogBuilder $recipeWorkbenchIngredientCatalogBuilder,
        private readonly RecipeWorkbenchIfraOptionsBuilder $recipeWorkbenchIfraOptionsBuilder,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(ProductFamily $productFamily, ?Recipe $recipe, ?User $user, ?ProductType $productType = null): array
    {
        $savedDraft = $this->recipeWorkbenchService->draftPayload($recipe);
        $defaultCurrency = $user?->defaultCurrency() ?? 'EUR';
        $productType ??= $recipe?->productType;

        return [
            'productFamily' => [
                'id' => $productFamily->id,
                'name' => $productFamily->name,
                'slug' => $productFamily->slug,
                'calculation_basis' => $productFamily->calculation_basis,
            ],
            'productType' => $this->productTypeData($productType),
            'recipe' => $this->recipeData($recipe),
            'savedDraft' => $savedDraft,
            'phases' => $this->recipeWorkbenchService->phaseBlueprints($productFamily),
            'ingredients' => $this->recipeWorkbenchIngredientCatalogBuilder->build($user, $productFamily),
            'ifraProductCategories' => $this->recipeWorkbenchIfraOptionsBuilder->categories($productFamily),
            'defaultIfraProductCategoryId' => $productType?->default_ifra_product_category_id
                ?? $this->recipeWorkbenchIfraOptionsBuilder->defaultCategoryId($productFamily),
            'costing' => null,
            'costingLoaded' => false,
            'defaultCurrency' => $defaultCurrency,
            'currencies' => $this->currencyOptions(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function currencyOptions(): array
    {
        $currencies = config('currencies', []);

        return collect($currencies)
            ->mapWithKeys(fn (array $data, string $code): array => [
                $code => __('currencies.'.$code),
            ])
            ->sort()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function recipeData(?Recipe $recipe): ?array
    {
        if (! $recipe instanceof Recipe) {
            return null;
        }

        $hasSavedFormula = $recipe->relationLoaded('currentSavedVersion')
            ? $recipe->currentSavedVersion !== null
            : $recipe->currentSavedVersion()->exists();

        return [
            'id' => $recipe->id,
            'name' => $recipe->name,
            'description' => $recipe->description,
            'manufacturing_instructions' => $recipe->manufacturing_instructions,
            'featured_image_url' => $recipe->featuredImageUrl(),
            'has_saved_formula' => $hasSavedFormula,
            'saved_formula_url' => $hasSavedFormula
                ? route('recipes.saved', $recipe->id)
                : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function productTypeData(?ProductType $productType): ?array
    {
        if (! $productType instanceof ProductType) {
            return null;
        }

        return [
            'id' => $productType->id,
            'name' => $productType->name,
            'slug' => $productType->slug,
            'default_ifra_product_category_id' => $productType->default_ifra_product_category_id,
        ];
    }
}
