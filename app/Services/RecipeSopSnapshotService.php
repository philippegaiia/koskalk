<?php

namespace App\Services;

use App\Enums\MediaAssetUsageRole;
use App\Models\MediaAssetUsage;
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
        $currentVersion = RecipeVersion::withoutGlobalScopes()
            ->where('recipe_id', $recipe->id)
            ->where('is_current', true)
            ->first();

        if (! $currentVersion instanceof RecipeVersion) {
            return;
        }

        $currentVersion->update(['manufacturing_instructions' => $instructions]);
        $this->copySopMediaAssets($recipe, $currentVersion);
    }

    public function copySopMediaAssets(Recipe|RecipeVersion $source, Recipe|RecipeVersion $destination): void
    {
        $sourceUsages = MediaAssetUsage::query()
            ->where('usable_type', $source->getMorphClass())
            ->where('usable_id', $source->getKey())
            ->where('role', MediaAssetUsageRole::RecipeSop)
            ->orderBy('id')
            ->get(['media_asset_id']);

        MediaAssetUsage::query()
            ->where('usable_type', $destination->getMorphClass())
            ->where('usable_id', $destination->getKey())
            ->where('role', MediaAssetUsageRole::RecipeSop)
            ->delete();

        foreach ($sourceUsages as $usage) {
            MediaAssetUsage::query()->create([
                'media_asset_id' => $usage->media_asset_id,
                'usable_type' => $destination->getMorphClass(),
                'usable_id' => $destination->getKey(),
                'role' => MediaAssetUsageRole::RecipeSop,
            ]);
        }
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
                            'manufacturing_instructions' => __('media_library.validation.recipe_media_mismatch'),
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
