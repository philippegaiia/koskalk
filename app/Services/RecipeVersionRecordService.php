<?php

namespace App\Services;

use App\Models\ProductFamily;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use App\OwnerType;
use App\Visibility;
use Illuminate\Support\Str;

class RecipeVersionRecordService
{
    public function createRecipe(User $user, ProductFamily $productFamily, string $name, ?int $productTypeId = null): Recipe
    {
        $recipe = new Recipe([
            'product_family_id' => $productFamily->id,
            'product_type_id' => $productTypeId,
            'name' => $name,
            'slug' => $this->uniqueRecipeSlug($name),
            'owner_type' => OwnerType::User,
            'owner_id' => $user->id,
            'workspace_id' => null,
            'visibility' => Visibility::Private,
        ]);

        $recipe->save();

        return $recipe;
    }

    /**
     * @param  array<string, mixed>  $normalizedPayload
     */
    public function fillVersion(
        RecipeVersion $recipeVersion,
        Recipe $recipe,
        User $user,
        array $normalizedPayload,
        bool $isDraft,
    ): void {
        if ($recipe->product_type_id !== ($normalizedPayload['product_type_id'] ?? null)) {
            $recipe->product_type_id = $normalizedPayload['product_type_id'] ?? null;
        }

        if ($recipe->name !== $normalizedPayload['name']) {
            $recipe->name = $normalizedPayload['name'];
        }

        if ($recipe->isDirty()) {
            $recipe->save();
        }

        $recipeVersion->recipe()->associate($recipe);
        $recipeVersion->owner_type = OwnerType::User;
        $recipeVersion->owner_id = $user->id;
        $recipeVersion->workspace_id = null;
        $recipeVersion->visibility = Visibility::Private;
        $recipeVersion->is_draft = $isDraft;
        $recipeVersion->name = $normalizedPayload['name'];
        $recipeVersion->batch_size = $normalizedPayload['oil_weight'];
        $recipeVersion->batch_unit = $normalizedPayload['oil_unit'];
        $recipeVersion->manufacturing_mode = $normalizedPayload['manufacturing_mode'];
        $recipeVersion->exposure_mode = $normalizedPayload['exposure_mode'];
        $recipeVersion->regulatory_regime = $normalizedPayload['regulatory_regime'];
        $recipeVersion->ifra_product_category_id = $normalizedPayload['ifra_product_category_id'];
        $recipeVersion->water_settings = $normalizedPayload['water_settings'];
        $recipeVersion->calculation_context = $normalizedPayload['calculation_context'];
        $recipeVersion->saved_at = $isDraft ? null : ($recipeVersion->saved_at ?? now());
        $recipeVersion->catalog_reviewed_at = now();
        $recipeVersion->archived_at = null;
    }

    public function nextVersionNumber(Recipe $recipe): int
    {
        return ((int) RecipeVersion::withoutGlobalScopes()
            ->where('recipe_id', $recipe->id)
            ->max('version_number')) + 1;
    }

    /**
     * @return array<int, string>
     */
    public function freshWorkbenchRelations(): array
    {
        return [
            'recipe',
            'phases.items.ingredient',
            'phases.items.ingredient.sapProfile',
            'phases.items.ingredient.fattyAcidEntries.fattyAcid',
            'packagingItems',
            'packagingItems.packagingItem',
        ];
    }

    private function uniqueRecipeSlug(string $name): string
    {
        $baseSlug = Str::slug($name);
        $slug = $baseSlug !== '' ? $baseSlug : 'soap-formula';
        $suffix = 1;

        while (Recipe::withoutGlobalScopes()->where('slug', $slug)->exists()) {
            $slug = "{$baseSlug}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}
