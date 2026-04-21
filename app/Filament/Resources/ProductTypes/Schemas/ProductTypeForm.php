<?php

namespace App\Filament\Resources\ProductTypes\Schemas;

use App\Models\IfraProductCategory;
use App\Services\MediaStorage;
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
                        Select::make('product_family_id')
                            ->label('Product family')
                            ->relationship(name: 'productFamily', titleAttribute: 'name')
                            ->searchable()
                            ->preload()
                            ->required(),
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
                        Select::make('default_ifra_product_category_id')
                            ->label('Default IFRA category')
                            ->relationship(name: 'defaultIfraProductCategory', titleAttribute: 'name')
                            ->getOptionLabelFromRecordUsing(fn (IfraProductCategory $record): string => $record->optionLabel())
                            ->searchable(['code', 'name', 'short_name'])
                            ->preload()
                            ->nullable()
                            ->helperText('Suggestion only. The formula keeps its own editable IFRA category.'),
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
