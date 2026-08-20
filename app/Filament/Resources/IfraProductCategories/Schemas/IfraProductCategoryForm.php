<?php

namespace App\Filament\Resources\IfraProductCategories\Schemas;

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
                            ->unique()
                            ->maxLength(255),
                        TextInput::make('name')
                            ->label('Official label')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('short_name')
                            ->label('Short label')
                            ->helperText('Use the user-facing shorthand, such as Soap / shower gel or Hair rinse-off.')
                            ->maxLength(255),
                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true),
                        Textarea::make('description')
                            ->label('Full description')
                            ->rows(4)
                            ->columnSpanFull(),
                    ])
                    ->columns([
                        'md' => 2,
                    ]),
            ]);
    }
}
