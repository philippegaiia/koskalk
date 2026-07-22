<?php

namespace App\Services;

use App\Models\Recipe;
use App\Models\RecipeVersion;

class RecipeSopSnapshotService
{
    public function syncCurrentVersion(Recipe $recipe, ?string $instructions): void
    {
        RecipeVersion::withoutGlobalScopes()
            ->where('recipe_id', $recipe->id)
            ->where('is_current', true)
            ->update([
                'manufacturing_instructions' => $instructions,
            ]);
    }
}
