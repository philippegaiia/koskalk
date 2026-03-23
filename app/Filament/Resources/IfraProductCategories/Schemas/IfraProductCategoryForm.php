<?php

namespace App\Filament\Resources\IfraProductCategories\Schemas;

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class IfraProductCategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Category Identity')
                    ->description('These IFRA product categories define the compliance context a formula is evaluated against.')
                    ->icon(Heroicon::Squares2x2)
                    ->schema([
                        TextInput::make('code')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255),
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('short_name')
                            ->maxLength(255),
                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true),
                        Textarea::make('description')
                            ->rows(4)
                            ->columnSpanFull(),
                    ])
                    ->columns([
                        'md' => 2,
                    ]),
                Section::make('Product Family Mapping')
                    ->description('Map product families to the IFRA categories they can use so future compliance runs can resolve the right context quickly.')
                    ->icon(Heroicon::Link)
                    ->schema([
                        Repeater::make('productFamilyMappings')
                            ->relationship()
                            ->schema([
                                Select::make('product_family_id')
                                    ->relationship(name: 'productFamily', titleAttribute: 'name')
                                    ->searchable()
                                    ->preload()
                                    ->required(),
                                Toggle::make('is_default')
                                    ->label('Default for family')
                                    ->default(false),
                                TextInput::make('sort_order')
                                    ->numeric()
                                    ->default(1)
                                    ->inputMode('numeric')
                                    ->step(1),
                            ])
                            ->columns([
                                'md' => 3,
                            ])
                            ->defaultItems(0)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
