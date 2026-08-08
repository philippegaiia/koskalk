<?php

namespace App\Services;

use App\Enums\MediaAssetStatus;
use App\Models\MediaAsset;
use App\Models\Recipe;
use App\Support\RichContentAttachmentPaths;
use Filament\Forms\Components\RichEditor\FileAttachmentProviders\Contracts\FileAttachmentProvider;
use Filament\Forms\Components\RichEditor\RichContentAttribute;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use RuntimeException;

class RecipeRichContentAttachmentProvider implements FileAttachmentProvider
{
    private ?RichContentAttribute $attribute = null;

    /**
     * @var array<string, array<string, string|null>>
     */
    private array $mediaAssetUrlsByRecipe = [];

    /**
     * @var array<string, Collection<int, string>>
     */
    private array $mediaAssetPublicIdsByRecipe = [];

    public function __construct(private readonly RecipeMediaReferenceService $recipeMediaReferenceService) {}

    public function attribute(RichContentAttribute $attribute): static
    {
        $this->attribute = $attribute;

        return $this;
    }

    public function content(mixed $content): static
    {
        $recipe = $this->recipe();

        if ($recipe instanceof Recipe) {
            $this->mediaAssetPublicIdsByRecipe[$this->recipeCacheKey($recipe)] =
                RichContentAttachmentPaths::extractMediaAssetPublicIds($content);
        }

        return $this;
    }

    public function getFileAttachmentUrl(mixed $file): ?string
    {
        $recipe = $this->recipe();

        if (! is_string($file) || ! $recipe instanceof Recipe) {
            return null;
        }

        if (! str_starts_with($file, RichContentAttachmentPaths::MEDIA_ASSET_IDENTITY_PREFIX)) {
            return MediaStorage::recipeUrl($recipe, $file);
        }

        $publicId = substr($file, strlen(RichContentAttachmentPaths::MEDIA_ASSET_IDENTITY_PREFIX));

        if ($publicId === '') {
            return null;
        }

        $recipeCacheKey = $this->recipeCacheKey($recipe);

        if (! array_key_exists($recipeCacheKey, $this->mediaAssetUrlsByRecipe)) {
            $attributeName = $this->attribute?->getName();
            $referencedPublicIds = $this->mediaAssetPublicIdsByRecipe[$recipeCacheKey]
                ?? RichContentAttachmentPaths::extractMediaAssetPublicIds(
                    is_string($attributeName) ? $recipe->getAttribute($attributeName) : null,
                );
            $this->primeMediaAssetUrls(
                $recipe,
                $referencedPublicIds->push($publicId)->unique()->values(),
            );
        } elseif (! array_key_exists($publicId, $this->mediaAssetUrlsByRecipe[$recipeCacheKey])) {
            $this->primeMediaAssetUrls($recipe, collect([$publicId]));
        }

        return $this->mediaAssetUrlsByRecipe[$recipeCacheKey][$publicId] ?? null;
    }

    /**
     * @param  Collection<int, string>  $publicIds
     */
    private function primeMediaAssetUrls(Recipe $recipe, Collection $publicIds): void
    {
        $recipeCacheKey = $this->recipeCacheKey($recipe);
        $this->mediaAssetUrlsByRecipe[$recipeCacheKey] ??= [];

        $missingPublicIds = $publicIds
            ->reject(fn (string $publicId): bool => array_key_exists(
                $publicId,
                $this->mediaAssetUrlsByRecipe[$recipeCacheKey],
            ))
            ->values();

        if ($missingPublicIds->isEmpty()) {
            return;
        }

        foreach ($missingPublicIds as $missingPublicId) {
            $this->mediaAssetUrlsByRecipe[$recipeCacheKey][$missingPublicId] = null;
        }

        MediaAsset::query()
            ->where('workspace_id', $recipe->workspace_id)
            ->whereIn('public_id', $missingPublicIds)
            ->where('status', MediaAssetStatus::Ready)
            ->get()
            ->each(function (MediaAsset $asset) use ($recipeCacheKey): void {
                $this->mediaAssetUrlsByRecipe[$recipeCacheKey][$asset->public_id] =
                    route('media.show', [$asset, 'master']);
            });
    }

    private function recipeCacheKey(Recipe $recipe): string
    {
        return implode(':', [
            $recipe->getMorphClass(),
            $recipe->getKey(),
            $recipe->workspace_id,
        ]);
    }

    public function saveUploadedFileAttachment(UploadedFile $file): mixed
    {
        $recipe = $this->recipe();

        if (! $recipe instanceof Recipe) {
            throw new RuntimeException('Save the formula before adding recipe attachments.');
        }

        return MediaStorage::storeRecipeResizedWebp(
            $file,
            MediaStorage::recipeDirectory($recipe, 'rich-content'),
            MediaStorage::recipeRichContentImagesWidth(),
            MediaStorage::recipeRichContentImagesHeight(),
            MediaStorage::recipeRichContentImagesQuality(),
        );
    }

    public function getDefaultFileAttachmentVisibility(): ?string
    {
        return MediaStorage::recipeVisibility();
    }

    public function isExistingRecordRequiredToSaveNewFileAttachments(): bool
    {
        return true;
    }

    public function cleanUpFileAttachments(array $exceptIds): void
    {
        if ($this->recipe()?->hasPendingRichContentState()) {
            return;
        }

        $currentAttachmentIds = $this->currentAttachmentIds();

        if ($currentAttachmentIds->isEmpty()) {
            return;
        }

        $preservedAttachmentIds = collect($exceptIds)
            ->filter(fn (mixed $value): bool => is_string($value))
            ->merge($this->otherAttributeAttachmentIds())
            ->merge($this->historicalSopAttachmentIds())
            ->unique()
            ->values();

        $currentAttachmentIds
            ->diff($preservedAttachmentIds)
            ->each(function (string $path): void {
                MediaStorage::deleteRecipePath($path);
            });
    }

    /**
     * @return Collection<int, string>
     */
    private function currentAttachmentIds(): Collection
    {
        $recipe = $this->recipe();
        $attributeName = $this->attribute?->getName();

        if ($recipe === null || ! is_string($attributeName) || $attributeName === '') {
            return collect();
        }

        return $recipe->richContentAttachmentPaths($attributeName);
    }

    /**
     * @return Collection<int, string>
     */
    private function otherAttributeAttachmentIds(): Collection
    {
        $recipe = $this->recipe();
        $attributeName = $this->attribute?->getName();

        if ($recipe === null || ! is_string($attributeName) || $attributeName === '') {
            return collect();
        }

        return $recipe->otherRichContentAttachmentPaths($attributeName);
    }

    /** @return Collection<int, string> */
    private function historicalSopAttachmentIds(): Collection
    {
        $recipe = $this->recipe();

        return $recipe instanceof Recipe
            ? $this->recipeMediaReferenceService->historicalSopPaths($recipe)
            : collect();
    }

    private function recipe(): ?Recipe
    {
        $model = $this->attribute?->getModel();

        return $model instanceof Recipe ? $model : null;
    }
}
