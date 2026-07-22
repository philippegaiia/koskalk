<?php

namespace App\Services;

use App\Models\ProductFamily;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RecipeDraftSaver
{
    public function __construct(
        private readonly RecipeVersionRecordService $recipeVersionRecordService,
        private readonly RecipeVersionStructureSynchronizer $recipeVersionStructureSynchronizer,
        private readonly RecipeSopSnapshotService $recipeSopSnapshotService,
    ) {}

    /**
     * @param  array<string, mixed>  $normalizedPayload
     */
    public function save(
        User $user,
        ProductFamily $productFamily,
        array $normalizedPayload,
        ?Recipe $recipe = null,
        ?Recipe $sopSourceRecipe = null,
    ): RecipeVersion {
        return DB::transaction(function () use ($normalizedPayload, $productFamily, $recipe, $sopSourceRecipe, $user): RecipeVersion {
            $recipe ??= $this->recipeVersionRecordService->createRecipe(
                $user,
                $productFamily,
                $normalizedPayload['name'],
                $normalizedPayload['product_type_id'] ?? null,
            );

            if ($sopSourceRecipe instanceof Recipe && ! $sopSourceRecipe->is($recipe)) {
                $normalizedPayload['manufacturing_instructions'] = $this->recipeSopSnapshotService->duplicateInstructions(
                    $sopSourceRecipe,
                    $recipe,
                    $normalizedPayload['manufacturing_instructions'] ?? null,
                );
            }

            $currentVersion = RecipeVersion::withoutGlobalScopes()
                ->where('recipe_id', $recipe->id)
                ->where('is_current', true)
                ->first();

            if (! $currentVersion instanceof RecipeVersion) {
                $currentVersion = new RecipeVersion;
                $currentVersion->recipe()->associate($recipe);
                $currentVersion->version_number = $this->recipeVersionRecordService->nextVersionNumber($recipe);
                $currentVersion->is_current = true;
            }

            $this->recipeVersionRecordService->fillVersion(
                $currentVersion,
                $recipe,
                $user,
                $normalizedPayload,
                true,
            );
            $currentVersion->save();

            $this->recipeVersionStructureSynchronizer->sync($currentVersion, $user, $normalizedPayload);

            return $currentVersion->fresh($this->recipeVersionRecordService->freshWorkbenchRelations());
        });
    }
}
