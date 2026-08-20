<?php

namespace App\Filament\Resources\ProductTypes\Schemas;

use App\Services\MediaStorage;
use App\Support\FilamentUploadMetadata;
use Filament\Forms\Components\BaseFileUpload;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class ProductTypeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Product type identity')
                    ->description('Platform-managed categories used for recipe cards, filters, defaults, and future translations.')
                    ->icon(Heroicon::Squares2x2)
                    ->schema([
                        Select::make('product_category_id')
                            ->label('Product category')
                            ->relationship(name: 'productCategory', titleAttribute: 'name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Select::make('productFamilies')
                            ->label('Compatible families')
                            ->relationship(name: 'productFamilies', titleAttribute: 'name')
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->required()
                            ->helperText('Calculation engines this finished Product Type can use.'),
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('slug')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('sort_order')
                            ->numeric()
                            ->inputMode('numeric')
                            ->step(1)
                            ->default(10),
                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true),
                        Textarea::make('description')
                            ->rows(3)
                            ->columnSpanFull(),
                    ])
                    ->columns([
                        'md' => 2,
                    ]),
                Section::make('Fallback image')
                    ->description('Used by recipe cards when the recipe has no uploaded image.')
                    ->icon(Heroicon::DocumentText)
                    ->schema([
                        FileUpload::make('fallback_image_path')
                            ->label('Fallback image')
                            ->image()
                            ->acceptedFileTypes(['image/jpeg', 'image/webp'])
                            ->maxSize(MediaStorage::recipeFeaturedImagesMaxSize())
                            ->disk(MediaStorage::publicDisk())
                            ->directory('product-types/fallback-images')
                            ->visibility(MediaStorage::publicVisibility())
                            ->storeFileNamesIn('fallback_image_original_name')
                            ->getUploadedFileUsing(fn (BaseFileUpload $component, string $file, string|array|null $storedFileNames): ?array => FilamentUploadMetadata::applyDisplayName(
                                $component->getUploadedFile($file, $storedFileNames),
                                $storedFileNames,
                                'Current image',
                            ))
                            ->deleteUploadedFileUsing(function (string $file): void {
                                MediaStorage::deletePublicPath($file);
                            })
                            ->saveUploadedFileUsing(fn (BaseFileUpload $component, TemporaryUploadedFile $file): string => MediaStorage::storeFittedWebp(
                                $file,
                                (string) $component->getDirectory(),
                                MediaStorage::recipeFeaturedImagesWidth(),
                                MediaStorage::recipeFeaturedImagesHeight(),
                                MediaStorage::recipeFeaturedImagesQuality(),
                            ))
                            ->imageEditor()
                            ->imageAspectRatio('4:3')
                            ->imageEditorAspectRatioOptions(['4:3'])
                            ->automaticallyOpenImageEditorForAspectRatio()
                            ->helperText('JPG or WebP, up to 1 MB. Stored as a 4:3 image up to 800x600.')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
