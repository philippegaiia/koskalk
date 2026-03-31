<?php

namespace App\Filament\Resources\FattyAcids\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class FattyAcidForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identity')
                    ->schema([
                        TextInput::make('key')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('short_name')
                            ->maxLength(255),
                    ])
                    ->columns([
                        'md' => 3,
                    ]),
                Section::make('Chemistry')
                    ->schema([
                        TextInput::make('chain_length')
                            ->label('Chain length (C)')
                            ->numeric()
                            ->integer()
                            ->minValue(1),
                        TextInput::make('double_bonds')
                            ->label('Double bonds')
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->default(0),
                        TextInput::make('saturation_class')
                            ->label('Saturation class')
                            ->maxLength(32),
                        TextInput::make('iodine_factor')
                            ->label('Iodine factor')
                            ->numeric()
                            ->inputMode('decimal'),
                        TextInput::make('default_group_key')
                            ->label('Default group key')
                            ->maxLength(32),
                    ])
                    ->columns([
                        'md' => 3,
                    ]),
                Section::make('Display & Visibility')
                    ->schema([
                        TextInput::make('display_order')
                            ->label('Display order')
                            ->numeric()
                            ->integer()
                            ->default(1),
                        Toggle::make('is_core')
                            ->label('Core fatty acid')
                            ->default(false),
                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true),
                        TextInput::make('default_hidden_below_percent')
                            ->label('Hide below %')
                            ->numeric()
                            ->inputMode('decimal')
                            ->helperText('Hide this fatty acid in profiles when its percentage is below this threshold.'),
                    ])
                    ->columns([
                        'md' => 2,
                    ]),
            ]);
    }
}
