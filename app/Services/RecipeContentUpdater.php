<?php

namespace App\Services;

use App\Models\Recipe;
use App\Rules\MaximumRichContentImages;
use App\Support\RichContentAttachmentPaths;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RecipeContentUpdater
{
    public function __construct(
        private readonly RecipeSopSnapshotService $recipeSopSnapshotService,
        private readonly RecipeMediaReferenceService $recipeMediaReferenceService,
    ) {}

    /**
     * @param  array{description:?string, manufacturing_instructions:?string, featured_image_path:?string, featured_image_original_name:?string}  $state
     */
    public function update(Recipe $recipe, array $state): Recipe
    {
        $this->validate($recipe, $state);
        $removedFeaturedImagePath = null;
        $removedRichContentAttachmentPaths = collect();

        $updatedRecipe = DB::transaction(function () use ($recipe, $state, &$removedFeaturedImagePath, &$removedRichContentAttachmentPaths): Recipe {
            $previousFeaturedImagePath = $recipe->featured_image_path;
            $previousRichContentAttachmentPaths = $recipe->richContentAttachmentPaths();
            $featuredImagePath = $state['featured_image_path'] ?? null;

            $recipe->fill([
                'description' => $state['description'] ?? null,
                'manufacturing_instructions' => $state['manufacturing_instructions'] ?? null,
                'featured_image_path' => $featuredImagePath,
                'featured_image_original_name' => filled($featuredImagePath)
                    ? ($state['featured_image_original_name'] ?? null)
                    : null,
            ]);
            $recipe->save();
            $this->recipeSopSnapshotService->syncCurrentVersion(
                $recipe,
                $recipe->manufacturing_instructions,
            );

            $removedFeaturedImagePath = $previousFeaturedImagePath !== $recipe->featured_image_path
                ? $previousFeaturedImagePath
                : null;
            $removedRichContentAttachmentPaths = $previousRichContentAttachmentPaths
                ->diff($recipe->richContentAttachmentPaths())
                ->values();

            return $recipe->fresh();
        });

        DB::afterCommit(function () use ($removedFeaturedImagePath, $removedRichContentAttachmentPaths, $updatedRecipe): void {
            MediaStorage::deleteRecipePath($removedFeaturedImagePath);
            $this->recipeMediaReferenceService->deleteIfUnreferenced(
                $updatedRecipe,
                $removedRichContentAttachmentPaths,
            );
        });

        return $updatedRecipe;
    }

    /**
     * @param  array{description:?string, manufacturing_instructions:?string, featured_image_path:?string, featured_image_original_name:?string}  $state
     */
    public function validate(Recipe $recipe, array $state): void
    {
        Validator::make([
            'description' => $state['description'] ?? null,
            'manufacturing_instructions' => $state['manufacturing_instructions'] ?? null,
        ], [
            'description' => [new MaximumRichContentImages(2, 'workbench.instructions.description_image_limit')],
            'manufacturing_instructions' => [new MaximumRichContentImages(8, 'workbench.instructions.procedure_image_limit')],
        ])->validate();

        $existingPaths = $recipe->mediaPaths();
        $submittedPathsByField = [
            'description' => RichContentAttachmentPaths::extractImageIdentities($state['description'] ?? null),
            'manufacturing_instructions' => RichContentAttachmentPaths::extractImageIdentities(
                $state['manufacturing_instructions'] ?? null,
            ),
            'featured_image_path' => collect([$state['featured_image_path'] ?? null])
                ->filter(fn (mixed $path): bool => is_string($path) && $path !== ''),
        ];

        foreach ($submittedPathsByField as $field => $submittedPaths) {
            $invalidPath = $submittedPaths->first(fn (string $path): bool => ! $existingPaths->contains($path)
                && ! MediaStorage::isRecipePath($recipe, $path));

            if (is_string($invalidPath)) {
                throw ValidationException::withMessages([
                    $field => 'The selected recipe media does not belong to this formula.',
                ]);
            }
        }
    }
}
