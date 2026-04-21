<?php

namespace App\Services;

use App\Models\Recipe;
use App\Models\RecipeVersion;
use Illuminate\Support\Facades\DB;

class RecipeVersionDeletionService
{
    /**
     * @return array{deleted_draft: bool, last_published_deleted: bool}
     */
    public function delete(Recipe $recipe, RecipeVersion $version): array
    {
        return DB::transaction(function () use ($recipe, $version): array {
            $lockedRecipe = Recipe::withoutGlobalScopes()
                ->whereKey($recipe->id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedVersion = RecipeVersion::withoutGlobalScopes()
                ->whereKey($version->id)
                ->where('recipe_id', $lockedRecipe->id)
                ->lockForUpdate()
                ->firstOrFail();

            $wasDraft = $lockedVersion->is_draft;
            $wasPublished = ! $wasDraft;

            $lockedVersion->delete();

            $hasPublishedVersions = RecipeVersion::withoutGlobalScopes()
                ->where('recipe_id', $lockedRecipe->id)
                ->where('is_draft', false)
                ->exists();

            return [
                'deleted_draft' => $wasDraft,
                'last_published_deleted' => $wasPublished && ! $hasPublishedVersions,
            ];
        });
    }
}
