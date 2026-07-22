<?php

namespace App\Services;

use App\Models\Recipe;
use App\Rules\MinimumImageEdges;
use App\Support\FilamentUploadMetadata;
use Filament\Forms\Components\BaseFileUpload;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\RichEditor;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class RecipeWorkbenchContentFormSchema
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Recipe content')
                    ->description('Keep presentation copy and manufacturing steps separate, with the product image stored alongside them.')
                    ->columns([
                        'lg' => 12,
                    ])
                    ->schema([
                        RichEditor::make('manufacturing_instructions')
                            ->label('Manufacturing instructions')
                            ->helperText('Use this for process steps, timing, cautions, and print-ready production instructions.')
                            ->toolbarButtons([
                                ['bold', 'italic', 'underline', 'strike', 'link'],
                                ['h2', 'h3', 'blockquote', 'bulletList', 'orderedList'],
                                ['attachFiles', 'undo', 'redo'],
                            ])
                            ->fileAttachmentsDisk(MediaStorage::recipeDisk())
                            ->fileAttachmentsDirectory(fn (?Recipe $record): string => $record instanceof Recipe
                                ? MediaStorage::recipeDirectory($record, 'rich-content')
                                : 'recipes/pending/rich-content')
                            ->fileAttachmentsVisibility(MediaStorage::recipeVisibility())
                            ->fileAttachmentsAcceptedFileTypes([
                                'image/jpeg',
                                'image/png',
                                'image/webp',
                            ])
                            ->fileAttachmentsMaxSize(MediaStorage::recipeRichContentImagesMaxSize())
                            ->resizableImages()
                            ->extraInputAttributes([
                                'class' => 'min-h-[20rem] [&_.fi-fo-rich-editor-content]:min-h-[18rem]',
                            ])
                            ->columnSpan([
                                'lg' => 12,
                            ]),
                        RichEditor::make('description')
                            ->label('Presentation')
                            ->helperText('Use this for product story, benefits, positioning, and publication-ready notes.')
                            ->toolbarButtons([
                                ['bold', 'italic', 'underline', 'strike', 'link'],
                                ['h2', 'h3', 'blockquote', 'bulletList', 'orderedList'],
                                ['attachFiles', 'undo', 'redo'],
                            ])
                            ->fileAttachmentsDisk(MediaStorage::recipeDisk())
                            ->fileAttachmentsDirectory(fn (?Recipe $record): string => $record instanceof Recipe
                                ? MediaStorage::recipeDirectory($record, 'rich-content')
                                : 'recipes/pending/rich-content')
                            ->fileAttachmentsVisibility(MediaStorage::recipeVisibility())
                            ->fileAttachmentsAcceptedFileTypes([
                                'image/jpeg',
                                'image/png',
                                'image/webp',
                            ])
                            ->fileAttachmentsMaxSize(MediaStorage::recipeRichContentImagesMaxSize())
                            ->resizableImages()
                            ->extraInputAttributes([
                                'class' => 'min-h-[20rem] [&_.fi-fo-rich-editor-content]:min-h-[18rem]',
                            ])
                            ->columnSpan([
                                'lg' => 12,
                            ]),
                        FileUpload::make('featured_image_path')
                            ->label('Finished product image')
                            ->image()
                            ->disk(MediaStorage::recipeDisk())
                            ->directory(fn (?Recipe $record): string => $record instanceof Recipe
                                ? MediaStorage::recipeDirectory($record, 'featured-images')
                                : 'recipes/pending/featured-images')
                            ->visibility(MediaStorage::recipeVisibility())
                            ->storeFileNamesIn('featured_image_original_name')
                            ->getUploadedFileUsing(function (BaseFileUpload $component, string $file, string|array|null $storedFileNames, ?Recipe $record): ?array {
                                $url = $record instanceof Recipe
                                    ? MediaStorage::recipeUrl($record, $file)
                                    : null;

                                if ($url === null) {
                                    return null;
                                }

                                $metadata = FilamentUploadMetadata::applyDisplayName(
                                    $component->getUploadedFile($file, $storedFileNames),
                                    $storedFileNames,
                                    __('media.current_image'),
                                );

                                if ($metadata === null) {
                                    return null;
                                }

                                $metadata['url'] = $url;

                                return $metadata;
                            })
                            ->preventFilePathTampering(
                                allowFilePathUsing: fn (string $file, ?Recipe $record): bool => $record instanceof Recipe
                                    && MediaStorage::isRecipePath($record, $file),
                            )
                            ->deleteUploadedFileUsing(function (string $file): void {
                                MediaStorage::deleteRecipePath($file);
                            })
                            ->imagePreviewHeight('14rem')
                            ->panelLayout('compact')
                            ->acceptedFileTypes([
                                'image/jpeg',
                                'image/png',
                                'image/webp',
                            ])
                            ->maxSize(MediaStorage::recipeFeaturedImagesMaxSize())
                            ->rules([
                                new MinimumImageEdges(300, 500),
                            ])
                            ->saveUploadedFileUsing(function (BaseFileUpload $component, TemporaryUploadedFile $file, ?Recipe $record): string {
                                if (! $record instanceof Recipe) {
                                    throw new \RuntimeException('Save the formula before adding a featured image.');
                                }

                                return MediaStorage::storeRecipeResizedWebp(
                                    $file,
                                    (string) $component->getDirectory(),
                                    MediaStorage::recipeFeaturedImagesWidth(),
                                    MediaStorage::recipeFeaturedImagesHeight(),
                                    MediaStorage::recipeFeaturedImagesQuality(),
                                );
                            })
                            ->imageEditor()
                            ->helperText('Allowed: JPG, PNG, or WebP, up to 3 MB. Images keep their proportions and are stored up to 800×800.')
                            ->columnSpan([
                                'lg' => 12,
                            ]),
                    ]),
            ]);
    }
}
