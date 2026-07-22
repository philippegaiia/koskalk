<?php

namespace App\Services;

use App\Models\Recipe;
use App\Rules\MaximumRichContentImages;
use App\Rules\MinimumImageEdges;
use App\Support\FilamentUploadMetadata;
use Filament\Forms\Components\BaseFileUpload;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\RichEditor;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class RecipeWorkbenchContentFormSchema
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('workbench.instructions.presentation_title'))
                    ->schema([
                        Grid::make([
                            'default' => 1,
                            'lg' => 12,
                        ])
                            ->schema([
                                RichEditor::make('description')
                                    ->label(__('workbench.instructions.description_label'))
                                    ->helperText(__('workbench.instructions.description_help'))
                                    ->toolbarButtons(fn (?Recipe $record): array => [
                                        ['bold', 'italic', 'underline', 'strike', 'link'],
                                        ['h2', 'h3', 'blockquote', 'bulletList', 'orderedList'],
                                        ...($record instanceof Recipe ? [['attachFiles', 'undo', 'redo']] : [['undo', 'redo']]),
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
                                    ->rules([
                                        new MaximumRichContentImages(2, 'workbench.instructions.description_image_limit'),
                                    ])
                                    ->extraInputAttributes([
                                        'class' => 'min-h-[12rem] [&_.fi-fo-rich-editor-content]:min-h-[10rem]',
                                    ])
                                    ->columnSpan([
                                        'lg' => 8,
                                    ]),
                                FileUpload::make('featured_image_path')
                                    ->label(__('workbench.instructions.featured_label'))
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
                                    ->helperText(__('workbench.instructions.featured_help'))
                                    ->disabled(fn (?Recipe $record): bool => ! $record instanceof Recipe)
                                    ->columnSpan([
                                        'lg' => 4,
                                    ]),
                            ]),
                    ]),
                Section::make(__('workbench.instructions.procedure_label'))
                    ->schema([
                        Grid::make([
                            'default' => 1,
                            'lg' => 12,
                        ])
                            ->schema([
                                RichEditor::make('manufacturing_instructions')
                                    ->label(__('workbench.instructions.procedure_label'))
                                    ->helperText(__('workbench.instructions.procedure_help'))
                                    ->toolbarButtons(fn (?Recipe $record): array => [
                                        ['bold', 'italic', 'underline', 'strike', 'link'],
                                        ['h2', 'h3', 'blockquote', 'bulletList', 'orderedList'],
                                        ...($record instanceof Recipe ? [['attachFiles', 'undo', 'redo']] : [['undo', 'redo']]),
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
                                    ->rules([
                                        new MaximumRichContentImages(8, 'workbench.instructions.procedure_image_limit'),
                                    ])
                                    ->extraInputAttributes([
                                        'class' => 'min-h-[22rem] [&_.fi-fo-rich-editor-content]:min-h-[20rem]',
                                    ])
                                    ->columnSpan([
                                        'lg' => 12,
                                    ]),
                            ]),
                    ]),
            ]);
    }
}
