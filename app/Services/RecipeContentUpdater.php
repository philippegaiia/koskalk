<?php

namespace App\Services;

use App\Models\Recipe;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RecipeContentUpdater
{
    /**
     * @param  array{description:?string, manufacturing_instructions:?string, featured_image_path:?string}  $state
     */
    public function update(Recipe $recipe, array $state): Recipe
    {
        $pathsToDelete = collect();

        $updatedRecipe = DB::transaction(function () use ($recipe, $state, &$pathsToDelete): Recipe {
            $previousFeaturedImagePath = $recipe->featured_image_path;
            $previousRichContentAttachmentPaths = $recipe->richContentAttachmentPaths();

            $recipe->fill([
                'description' => $state['description'] ?? null,
                'manufacturing_instructions' => $state['manufacturing_instructions'] ?? null,
                'featured_image_path' => $state['featured_image_path'] ?? null,
            ]);
            $recipe->save();

            $pathsToDelete = $this->pathsToDelete(
                $previousFeaturedImagePath,
                $recipe->featured_image_path,
                $previousRichContentAttachmentPaths,
                $recipe->richContentAttachmentPaths(),
            );

            return $recipe->fresh();
        });

        $pathsToDelete->each(function (string $path): void {
            MediaStorage::deletePublicPath($path);
        });

        return $updatedRecipe;
    }

    /**
     * @param  Collection<int, string>  $previousRichContentAttachmentPaths
     * @param  Collection<int, string>  $currentRichContentAttachmentPaths
     * @return Collection<int, string>
     */
    private function pathsToDelete(
        ?string $previousFeaturedImagePath,
        ?string $currentFeaturedImagePath,
        Collection $previousRichContentAttachmentPaths,
        Collection $currentRichContentAttachmentPaths,
    ): Collection {
        $removedFeaturedImagePaths = collect();

        if ($previousFeaturedImagePath !== $currentFeaturedImagePath && filled($previousFeaturedImagePath)) {
            $removedFeaturedImagePaths->push($previousFeaturedImagePath);
        }

        return $removedFeaturedImagePaths
            ->merge($previousRichContentAttachmentPaths->diff($currentRichContentAttachmentPaths))
            ->unique()
            ->values();
    }
}
