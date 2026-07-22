<?php

namespace App\Services;

use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Support\RichContentAttachmentPaths;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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

    public function duplicateInstructions(
        Recipe $sourceRecipe,
        Recipe $destinationRecipe,
        ?string $instructions,
        bool $preserveDestinationPaths = false,
        bool $rejectInvalidPaths = false,
    ): ?string {
        if ($instructions === null || $instructions === '') {
            return $instructions;
        }

        return RichContentAttachmentPaths::extract($instructions)
            ->reduce(function (string $copiedInstructions, string $sourcePath) use ($sourceRecipe, $destinationRecipe, $preserveDestinationPaths, $rejectInvalidPaths): string {
                if (! MediaStorage::isRecipePath($sourceRecipe, $sourcePath)) {
                    if ($preserveDestinationPaths
                        && MediaStorage::isRecipePath($destinationRecipe, $sourcePath)
                        && Storage::disk(MediaStorage::recipeDisk())->exists($sourcePath)) {
                        return $copiedInstructions;
                    }

                    if ($rejectInvalidPaths) {
                        throw ValidationException::withMessages([
                            'manufacturing_instructions' => 'The selected recipe media does not belong to this formula.',
                        ]);
                    }

                    return $this->removeAttachmentImage($copiedInstructions, $sourcePath);
                }

                $disk = Storage::disk(MediaStorage::recipeDisk());

                if (! $disk->exists($sourcePath)) {
                    return $this->removeAttachmentImage($copiedInstructions, $sourcePath);
                }

                $extension = pathinfo($sourcePath, PATHINFO_EXTENSION);
                $destinationPath = MediaStorage::recipeDirectory($destinationRecipe, 'rich-content')
                    .'/'.Str::ulid()
                    .($extension !== '' ? '.'.$extension : '');

                if (! $disk->copy($sourcePath, $destinationPath)) {
                    return $this->removeAttachmentImage($copiedInstructions, $sourcePath);
                }

                return str_replace($sourcePath, $destinationPath, $copiedInstructions);
            }, $instructions);
    }

    private function removeAttachmentImage(string $instructions, string $attachmentPath): string
    {
        return preg_replace_callback(
            '/<img\b[^>]*>/i',
            fn (array $matches): string => RichContentAttachmentPaths::extract($matches[0])->contains($attachmentPath)
                ? ''
                : $matches[0],
            $instructions,
        ) ?? $instructions;
    }
}
