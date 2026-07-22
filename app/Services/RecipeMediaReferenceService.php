<?php

namespace App\Services;

use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Support\RichContentAttachmentPaths;
use Illuminate\Support\Collection;

class RecipeMediaReferenceService
{
    /** @return Collection<int, string> */
    public function allReferencedPaths(Recipe $recipe): Collection
    {
        return $recipe->richContentAttachmentPaths()
            ->merge($this->versionSopPaths($recipe))
            ->unique()
            ->values();
    }

    /** @return Collection<int, string> */
    public function historicalSopPaths(Recipe $recipe): Collection
    {
        return $this->versionSopPaths($recipe, isCurrent: false);
    }

    /** @param Collection<int, string> $candidatePaths */
    public function deleteIfUnreferenced(Recipe $recipe, Collection $candidatePaths): void
    {
        $freshRecipe = Recipe::withoutGlobalScopes()->find($recipe->getKey());

        if (! $freshRecipe instanceof Recipe) {
            return;
        }

        $referencedPaths = $this->allReferencedPaths($freshRecipe);

        $candidatePaths
            ->filter(fn (mixed $path): bool => is_string($path) && MediaStorage::isRecipePath($freshRecipe, $path))
            ->unique()
            ->diff($referencedPaths)
            ->each(function (string $path): void {
                MediaStorage::deleteRecipePath($path);
            });
    }

    /** @return Collection<int, string> */
    private function versionSopPaths(Recipe $recipe, ?bool $isCurrent = null): Collection
    {
        $query = RecipeVersion::withoutGlobalScopes()
            ->where('recipe_id', $recipe->getKey())
            ->select('manufacturing_instructions');

        if (is_bool($isCurrent)) {
            $query->where('is_current', $isCurrent);
        }

        return $query
            ->get()
            ->flatMap(fn (RecipeVersion $version): Collection => RichContentAttachmentPaths::extract(
                $version->manufacturing_instructions,
            ))
            ->unique()
            ->values();
    }
}
