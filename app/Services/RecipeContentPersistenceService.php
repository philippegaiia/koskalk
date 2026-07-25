<?php

namespace App\Services;

use App\MediaAssetUsageRole;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RecipeContentPersistenceService
{
    public function __construct(
        private readonly RecipeContentUpdater $recipeContentUpdater,
        private readonly MediaAssetUsageService $mediaAssetUsageService,
        private readonly RecipeSopMediaAssetSynchronizer $recipeSopMediaAssetSynchronizer,
        private readonly RecipeSopSnapshotService $recipeSopSnapshotService,
    ) {}

    /**
     * @param  array<string, mixed>  $state
     */
    public function update(
        User $user,
        Recipe $recipe,
        array $state,
        int|string|null $featuredMediaAssetId,
    ): Recipe {
        return DB::transaction(function () use ($featuredMediaAssetId, $recipe, $state, $user): Recipe {
            $updatedRecipe = $this->recipeContentUpdater->update($recipe, $state);

            $this->mediaAssetUsageService->syncSingle(
                $user,
                $updatedRecipe,
                MediaAssetUsageRole::RecipeFeatured,
                $featuredMediaAssetId,
            );
            $this->recipeSopMediaAssetSynchronizer->syncRecipeUsages(
                $user,
                $updatedRecipe,
                $updatedRecipe->manufacturing_instructions,
            );
            $this->recipeSopSnapshotService->syncCurrentVersion(
                $updatedRecipe,
                $updatedRecipe->manufacturing_instructions,
            );

            return $updatedRecipe;
        });
    }
}
