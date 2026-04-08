<?php

namespace App\Services;

use App\Models\ProductFamily;
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
    public function build(ProductFamily $productFamily, ?Recipe $recipe, ?User $user): array
    {
        $savedDraft = $this->recipeWorkbenchService->draftPayload($recipe);

        return [
            'productFamily' => [
                'id' => $productFamily->id,
                'name' => $productFamily->name,
                'slug' => $productFamily->slug,
                'calculation_basis' => $productFamily->calculation_basis,
            ],
            'recipe' => $this->recipeData($recipe),
            'savedDraft' => $savedDraft,
            'phases' => $this->recipeWorkbenchService->phaseBlueprints(),
            'ingredients' => $this->recipeWorkbenchIngredientCatalogBuilder->build($user),
            'ifraProductCategories' => $this->recipeWorkbenchIfraOptionsBuilder->categories($productFamily),
            'defaultIfraProductCategoryId' => $this->recipeWorkbenchIfraOptionsBuilder->defaultCategoryId($productFamily),
            'costing' => null,
            'costingLoaded' => false,
        ];
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
}
