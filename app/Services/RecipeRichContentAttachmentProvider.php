<?php

namespace App\Services;

use App\Models\Recipe;
use Filament\Forms\Components\RichEditor\FileAttachmentProviders\Contracts\FileAttachmentProvider;
use Filament\Forms\Components\RichEditor\RichContentAttribute;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;

class RecipeRichContentAttachmentProvider implements FileAttachmentProvider
{
    private ?RichContentAttribute $attribute = null;

    public function attribute(RichContentAttribute $attribute): static
    {
        $this->attribute = $attribute;

        return $this;
    }

    public function getFileAttachmentUrl(mixed $file): ?string
    {
        return is_string($file) ? MediaStorage::publicUrl($file) : null;
    }

    public function saveUploadedFileAttachment(UploadedFile $file): mixed
    {
        return MediaStorage::storeResizedWebp(
            $file,
            'recipes/rich-content',
            MediaStorage::recipeRichContentImagesWidth(),
            MediaStorage::recipeRichContentImagesHeight(),
            MediaStorage::recipeRichContentImagesQuality(),
        );
    }

    public function getDefaultFileAttachmentVisibility(): ?string
    {
        return MediaStorage::publicVisibility();
    }

    public function isExistingRecordRequiredToSaveNewFileAttachments(): bool
    {
        return false;
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
            ->unique()
            ->values();

        $currentAttachmentIds
            ->diff($preservedAttachmentIds)
            ->each(function (string $path): void {
                MediaStorage::deletePublicPath($path);
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

    private function recipe(): ?Recipe
    {
        $model = $this->attribute?->getModel();

        return $model instanceof Recipe ? $model : null;
    }
}
