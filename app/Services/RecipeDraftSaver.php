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
    ) {}

    /**
     * @param  array<string, mixed>  $normalizedPayload
     */
    public function save(User $user, ProductFamily $productFamily, array $normalizedPayload, ?Recipe $recipe = null): RecipeVersion
    {
        return DB::transaction(function () use ($normalizedPayload, $productFamily, $recipe, $user): RecipeVersion {
            $recipe ??= $this->recipeVersionRecordService->createRecipe(
                $user,
                $productFamily,
                $normalizedPayload['name'],
                $normalizedPayload['product_type_id'] ?? null,
            );

            $draftVersion = RecipeVersion::withoutGlobalScopes()
                ->where('recipe_id', $recipe->id)
                ->where('is_draft', true)
                ->first();

            if (! $draftVersion instanceof RecipeVersion) {
                $draftVersion = new RecipeVersion;
                $draftVersion->recipe()->associate($recipe);
                $draftVersion->version_number = $this->recipeVersionRecordService->nextVersionNumber($recipe);
                $draftVersion->is_draft = true;
            }

            $this->recipeVersionRecordService->fillVersion(
                $draftVersion,
                $recipe,
                $user,
                $normalizedPayload,
                true,
            );
            $draftVersion->save();

            $this->recipeVersionStructureSynchronizer->sync($draftVersion, $user, $normalizedPayload);

            return $draftVersion->fresh($this->recipeVersionRecordService->freshWorkbenchRelations());
        });
    }
}
