<?php

namespace App\Filament\Resources\Allergens\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class AllergenForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identity')
                    ->description('Keep the declarable allergen reference catalog normalized and traceable for aromatic ingredient composition.')
                    ->icon(Heroicon::ExclamationTriangle)
                    ->schema([
                        TextInput::make('inci_name')
                            ->label('INCI label name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('cas_number')
                            ->label('CAS number')
                            ->maxLength(255),
                        TextInput::make('ec_number')
                            ->label('EC number')
                            ->maxLength(255),
                        TextInput::make('common_name_en')
                            ->label('Common name (EN)')
                            ->maxLength(255),
                        TextInput::make('common_name_fr')
                            ->label('Common name (FR)')
                            ->maxLength(255),
                    ])
                    ->columns([
                        'md' => 2,
                    ]),
                Section::make('Source Traceability')
                    ->description('Reference allergens stay platform-managed and seeded from official or curated source files.')
                    ->icon(Heroicon::DocumentText)
                    ->schema([
                        TextInput::make('source_name')
                            ->default('EU allergen list')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('source_file')
                            ->default('admin')
                            ->required()
                            ->maxLength(255),
                    ])
                    ->columns([
                        'md' => 2,
                    ]),
            ]);
    }
}
