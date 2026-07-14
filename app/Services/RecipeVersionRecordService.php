<?php

namespace App\Services;

use App\Models\ProductFamily;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\RegulatoryRegime;
use App\Models\User;
use App\OwnerType;
use App\Visibility;
use Illuminate\Support\Str;

class RecipeVersionRecordService
{
    public function __construct(private readonly WorkspaceProvisioner $workspaceProvisioner) {}

    public function createRecipe(User $user, ProductFamily $productFamily, string $name, ?int $productTypeId = null): Recipe
    {
        $workspace = $this->workspaceProvisioner->ensureOwnerWorkspace($user);
        $recipe = new Recipe([
            'product_family_id' => $productFamily->id,
            'product_type_id' => $productTypeId,
            'name' => $name,
            'slug' => $this->uniqueRecipeSlug($name),
            'owner_type' => OwnerType::Workspace,
            'owner_id' => $workspace->id,
            'workspace_id' => $workspace->id,
            'created_by' => $user->id,
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
        bool $isCurrent,
    ): void {
        if ($recipe->workspace_id === null) {
            $workspace = $this->workspaceProvisioner->ensureOwnerWorkspace($user);
            $recipe->owner_type = OwnerType::Workspace;
            $recipe->owner_id = $workspace->id;
            $recipe->workspace_id = $workspace->id;
            $recipe->visibility = Visibility::Workspace;
            $recipe->created_by ??= $user->id;
        }

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
        $recipeVersion->owner_type = OwnerType::Workspace;
        $recipeVersion->owner_id = $recipe->workspace_id;
        $recipeVersion->workspace_id = $recipe->workspace_id;
        $recipeVersion->is_current = $isCurrent;
        $recipeVersion->name = $normalizedPayload['name'];
        $recipeVersion->batch_size = $normalizedPayload['oil_weight'];
        $recipeVersion->batch_unit = $normalizedPayload['oil_unit'];
        $recipeVersion->manufacturing_mode = $normalizedPayload['manufacturing_mode'];
        $recipeVersion->exposure_mode = $normalizedPayload['exposure_mode'];
        $recipeVersion->regulatory_regime = $normalizedPayload['regulatory_regime'];
        $recipeVersion->regulatory_regime_id = RegulatoryRegime::query()
            ->where('code', $normalizedPayload['regulatory_regime'])
            ->value('id');
        $recipeVersion->ifra_product_category_id = $normalizedPayload['ifra_product_category_id'];
        $recipeVersion->final_ingredient_list = $normalizedPayload['final_ingredient_list'];
        $recipeVersion->final_ingredient_list_basis_hash = $normalizedPayload['final_ingredient_list_basis_hash'];
        $recipeVersion->final_plain_ingredient_list = $normalizedPayload['final_plain_ingredient_list'];
        $recipeVersion->final_plain_ingredient_list_basis_hash = $normalizedPayload['final_plain_ingredient_list_basis_hash'];
        $recipeVersion->water_settings = $normalizedPayload['water_settings'];
        $recipeVersion->calculation_context = $normalizedPayload['calculation_context'];
        $recipeVersion->saved_at = $isCurrent ? null : ($recipeVersion->saved_at ?? now());
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
