<?php

namespace App\Services;

use App\Models\Recipe;
use App\Models\RecipeVersion;
use Illuminate\Support\Facades\DB;

class RecipeVersionDeletionService
{
    /**
     * @return array{deleted_current: bool, last_published_deleted: bool}
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

            $wasCurrent = $lockedVersion->is_current;
            $wasPublished = ! $wasCurrent;

            $lockedVersion->delete();

            $hasPublishedVersions = RecipeVersion::withoutGlobalScopes()
                ->where('recipe_id', $lockedRecipe->id)
                ->where('is_current', false)
                ->exists();

            return [
                'deleted_current' => $wasCurrent,
                'last_published_deleted' => $wasPublished && ! $hasPublishedVersions,
            ];
        });
    }
}
