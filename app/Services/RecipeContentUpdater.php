<?php

namespace App\Services;

use App\Models\Recipe;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecipeContentUpdater
{
    /**
     * @param  array{description:?string, manufacturing_instructions:?string, featured_image_path:?string}  $state
     */
    public function update(Recipe $recipe, array $state): Recipe
    {
        $this->validateMediaPaths($recipe, $state);
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
            MediaStorage::deleteRecipePath($path);
        });

        return $updatedRecipe;
    }

    /**
     * @param  array{description:?string, manufacturing_instructions:?string, featured_image_path:?string}  $state
     */
    private function validateMediaPaths(Recipe $recipe, array $state): void
    {
        $submittedRecipe = clone $recipe;
        $submittedRecipe->fill($state);
        $existingPaths = $recipe->mediaPaths();
        $invalidPath = $submittedRecipe->mediaPaths()
            ->first(fn (string $path): bool => ! $existingPaths->contains($path)
                && ! MediaStorage::isRecipePath($recipe, $path));

        if (is_string($invalidPath)) {
            throw ValidationException::withMessages([
                'featured_image_path' => 'The selected recipe media does not belong to this formula.',
            ]);
        }
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
