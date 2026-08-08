<?php

namespace App\Services;

use App\Enums\MediaAssetStatus;
use App\Models\MediaAsset;
use App\Models\Recipe;
use App\Support\RichContentAttachmentPaths;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecipeContentUpdater
{
    public function __construct(
        private readonly RecipeMediaReferenceService $recipeMediaReferenceService,
        private readonly RecipeSopMediaAssetSynchronizer $recipeSopMediaAssetSynchronizer,
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
            $featuredImagePath = array_key_exists('featured_image_path', $state)
                ? $state['featured_image_path']
                : $recipe->featured_image_path;

            $recipe->fill([
                'description' => $state['description'] ?? null,
                'manufacturing_instructions' => $state['manufacturing_instructions'] ?? null,
                'featured_image_path' => $featuredImagePath,
                'featured_image_original_name' => filled($featuredImagePath)
                    ? (array_key_exists('featured_image_original_name', $state)
                        ? $state['featured_image_original_name']
                        : $recipe->featured_image_original_name)
                    : null,
            ]);
            $recipe->save();

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
        $this->validateInlineImages($recipe, $state);

        $existingPaths = $recipe->mediaPaths();
        $submittedPathsByField = [
            'description' => $this->legacyImageIdentities($state['description'] ?? null),
            'manufacturing_instructions' => RichContentAttachmentPaths::extractImageIdentities(
                $state['manufacturing_instructions'] ?? null,
            )->reject(fn (string $identity): bool => str_starts_with(
                $identity,
                RichContentAttachmentPaths::MEDIA_ASSET_IDENTITY_PREFIX,
            )),
            'featured_image_path' => collect([$state['featured_image_path'] ?? null])
                ->filter(fn (mixed $path): bool => is_string($path) && $path !== ''),
        ];

        foreach ($submittedPathsByField as $field => $submittedPaths) {
            $invalidPath = $submittedPaths->first(fn (string $path): bool => ! $existingPaths->contains($path)
                && ! MediaStorage::isRecipePath($recipe, $path));

            if (is_string($invalidPath)) {
                throw ValidationException::withMessages([
                    $field => __('media_library.validation.recipe_media_mismatch'),
                ]);
            }
        }
    }

    /**
     * @return array<int, int>
     */
    public function mediaAssetIdsForManufacturingInstructions(Recipe $recipe, mixed $instructions): array
    {
        return $this->recipeSopMediaAssetSynchronizer->assetIds($recipe, $instructions);
    }

    /**
     * Existing recipe-owned rich-editor images remain viewable and editable as
     * legacy content. New procedure images must come from the Media Library.
     *
     * @param  array<string, mixed>  $state
     */
    private function validateInlineImages(Recipe $recipe, array $state): void
    {
        $description = $state['description'] ?? null;
        $descriptionIdentities = RichContentAttachmentPaths::extractImageIdentities($description);
        $existingDescriptionIdentities = RichContentAttachmentPaths::extractImageIdentities($recipe->description);

        if (
            $this->containsUnidentifiedInlineImage($description)
            || $descriptionIdentities->diff($existingDescriptionIdentities)->isNotEmpty()
        ) {
            throw ValidationException::withMessages([
                'description' => __('media_library.validation.description_text_only'),
            ]);
        }

        $instructions = $state['manufacturing_instructions'] ?? null;
        $submittedLegacyIdentities = $this->legacyImageIdentities($instructions);
        $existingLegacyIdentities = $this->legacyImageIdentities($recipe->manufacturing_instructions);

        if (
            $this->containsUnidentifiedInlineImage($instructions)
            || $submittedLegacyIdentities->diff($existingLegacyIdentities)->isNotEmpty()
        ) {
            throw ValidationException::withMessages([
                'manufacturing_instructions' => __('media_library.validation.procedure_use_library'),
            ]);
        }

        $this->mediaAssetIdsForManufacturingInstructions($recipe, $instructions);

        if ($this->containsTamperedMediaAssetSource($recipe, $instructions)) {
            throw ValidationException::withMessages([
                'manufacturing_instructions' => __('media_library.validation.procedure_secure_url'),
            ]);
        }
    }

    /**
     * @return Collection<int, string>
     */
    private function legacyImageIdentities(mixed $content): Collection
    {
        return RichContentAttachmentPaths::extractImageIdentities($content)
            ->reject(fn (string $identity): bool => str_starts_with(
                $identity,
                RichContentAttachmentPaths::MEDIA_ASSET_IDENTITY_PREFIX,
            ))
            ->values();
    }

    private function containsUnidentifiedInlineImage(mixed $content): bool
    {
        if (is_string($content)) {
            preg_match_all('/<img\\b[^>]*>/i', $content, $imageMatches);

            return collect($imageMatches[0] ?? [])
                ->contains(fn (string $image): bool => RichContentAttachmentPaths::extractImageIdentities($image)->isEmpty());
        }

        if (! is_array($content)) {
            return false;
        }

        if (($content['type'] ?? null) === 'image') {
            return RichContentAttachmentPaths::extractImageIdentities($content)->isEmpty();
        }

        foreach ($content as $child) {
            if ($this->containsUnidentifiedInlineImage($child)) {
                return true;
            }
        }

        return false;
    }

    private function containsTamperedMediaAssetSource(Recipe $recipe, mixed $content): bool
    {
        $publicIds = RichContentAttachmentPaths::extractMediaAssetPublicIds($content);

        if ($publicIds->isEmpty()) {
            return false;
        }

        $trustedSources = MediaAsset::query()
            ->where('workspace_id', $recipe->workspace_id)
            ->where('status', MediaAssetStatus::Ready)
            ->whereIn('public_id', $publicIds)
            ->get()
            ->mapWithKeys(fn (MediaAsset $asset): array => [
                RichContentAttachmentPaths::mediaAssetIdentity($asset->public_id) => route('media.show', [$asset, 'master']),
            ])
            ->all();

        return $this->hasUntrustedMediaAssetSource($content, $trustedSources);
    }

    /**
     * @param  array<string, string>  $trustedSources
     */
    private function hasUntrustedMediaAssetSource(mixed $content, array $trustedSources): bool
    {
        if (is_string($content)) {
            preg_match_all('/<img\\b[^>]*>/i', $content, $imageMatches);

            foreach ($imageMatches[0] ?? [] as $image) {
                $identity = RichContentAttachmentPaths::extractImageIdentities($image)
                    ->first(fn (string $value): bool => str_starts_with(
                        $value,
                        RichContentAttachmentPaths::MEDIA_ASSET_IDENTITY_PREFIX,
                    ));

                if (! is_string($identity)) {
                    continue;
                }

                preg_match('/\\bsrc="([^"]*)"/i', $image, $sourceMatch);
                $source = isset($sourceMatch[1])
                    ? html_entity_decode($sourceMatch[1], ENT_QUOTES | ENT_HTML5)
                    : null;

                if (filled($source) && ($trustedSources[$identity] ?? null) !== $source) {
                    return true;
                }
            }

            return false;
        }

        if (! is_array($content)) {
            return false;
        }

        if (($content['type'] ?? null) === 'image') {
            $attributes = is_array($content['attrs'] ?? null) ? $content['attrs'] : [];
            $identity = $attributes['id'] ?? null;
            $source = $attributes['src'] ?? null;

            return is_string($identity)
                && str_starts_with($identity, RichContentAttachmentPaths::MEDIA_ASSET_IDENTITY_PREFIX)
                && filled($source)
                && ($trustedSources[$identity] ?? null) !== $source;
        }

        foreach ($content as $child) {
            if ($this->hasUntrustedMediaAssetSource($child, $trustedSources)) {
                return true;
            }
        }

        return false;
    }
}
