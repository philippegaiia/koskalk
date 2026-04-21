<?php

namespace App\Services;

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
                            ->fileAttachmentsDisk(MediaStorage::publicDisk())
                            ->fileAttachmentsDirectory('recipes/rich-content')
                            ->fileAttachmentsVisibility(MediaStorage::publicVisibility())
                            ->fileAttachmentsAcceptedFileTypes([
                                'image/jpeg',
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
                            ->fileAttachmentsDisk(MediaStorage::publicDisk())
                            ->fileAttachmentsDirectory('recipes/rich-content')
                            ->fileAttachmentsVisibility(MediaStorage::publicVisibility())
                            ->fileAttachmentsAcceptedFileTypes([
                                'image/jpeg',
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
                            ->disk(MediaStorage::publicDisk())
                            ->directory('recipes/featured-images')
                            ->visibility(MediaStorage::publicVisibility())
                            ->deleteUploadedFileUsing(function (string $file): void {
                                MediaStorage::deletePublicPath($file);
                            })
                            ->imagePreviewHeight('20rem')
                            ->panelLayout('integrated')
                            ->panelAspectRatio('4:3')
                            ->acceptedFileTypes([
                                'image/jpeg',
                                'image/webp',
                            ])
                            ->maxSize(MediaStorage::recipeFeaturedImagesMaxSize())
                            ->saveUploadedFileUsing(fn (BaseFileUpload $component, TemporaryUploadedFile $file): string => MediaStorage::storeResizedWebp(
                                $file,
                                (string) $component->getDirectory(),
                                (int) config('media.recipe_featured_images.max_width', 800),
                                (int) config('media.recipe_featured_images.max_height', 600),
                                MediaStorage::recipeFeaturedImagesQuality(),
                            ))
                            ->imageEditor()
                            ->imageAspectRatio('4:3')
                            ->imageEditorAspectRatioOptions(['4:3'])
                            ->imageEditorViewportWidth('800')
                            ->imageEditorViewportHeight('600')
                            ->automaticallyOpenImageEditorForAspectRatio()
                            ->helperText('Allowed: JPG or WebP, up to 1 MB. Recipe images are cropped to 4:3 and stored up to 800x600.')
                            ->columnSpan([
                                'lg' => 12,
                            ]),
                    ]),
            ]);
    }
}
