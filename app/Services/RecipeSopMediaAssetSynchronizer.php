<?php

namespace App\Services;

use App\Enums\MediaAssetStatus;
use App\Enums\MediaAssetUsageRole;
use App\Models\MediaAsset;
use App\Models\Recipe;
use App\Models\User;
use App\Support\RichContentAttachmentPaths;
use Illuminate\Validation\ValidationException;

class RecipeSopMediaAssetSynchronizer
{
    public function __construct(private readonly MediaAssetUsageService $mediaAssetUsageService) {}

    /**
     * @return array<int, int>
     */
    public function assetIds(Recipe $recipe, mixed $instructions): array
    {
        if (RichContentAttachmentPaths::countImageOccurrences($instructions) > 8) {
            throw ValidationException::withMessages([
                'manufacturing_instructions' => __('media_library.validation.procedure_image_limit', ['max' => 8]),
            ]);
        }

        $publicIds = RichContentAttachmentPaths::extractMediaAssetPublicIds($instructions);

        if ($publicIds->isEmpty()) {
            return [];
        }

        $assetsByPublicId = MediaAsset::query()
            ->where('workspace_id', $recipe->workspace_id)
            ->where('status', MediaAssetStatus::Ready)
            ->whereIn('public_id', $publicIds)
            ->get(['id', 'public_id'])
            ->keyBy('public_id');

        if ($assetsByPublicId->count() !== $publicIds->count()) {
            throw ValidationException::withMessages([
                'manufacturing_instructions' => __('media_library.validation.procedure_images_unavailable'),
            ]);
        }

        return $publicIds
            ->map(fn (string $publicId): int => $assetsByPublicId->get($publicId)->id)
            ->all();
    }

    public function syncRecipeUsages(User $user, Recipe $recipe, mixed $instructions): void
    {
        $this->mediaAssetUsageService->syncMany(
            $user,
            $recipe,
            MediaAssetUsageRole::RecipeSop,
            $this->assetIds($recipe, $instructions),
            maximum: 8,
        );
    }
}
