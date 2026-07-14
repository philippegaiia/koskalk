<?php

namespace App\Services;

use App\Models\Recipe;
use Filament\Forms\Components\RichEditor\FileAttachmentProviders\Contracts\FileAttachmentProvider;
use Filament\Forms\Components\RichEditor\RichContentAttribute;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use RuntimeException;

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
        $recipe = $this->recipe();

        return is_string($file) && $recipe instanceof Recipe
            ? MediaStorage::recipeUrl($recipe, $file)
            : null;
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

    private function recipe(): ?Recipe
    {
        $model = $this->attribute?->getModel();

        return $model instanceof Recipe ? $model : null;
    }
}
