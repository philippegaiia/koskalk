<?php

namespace App\Filament\Resources\Substances\Schemas;

use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class SubstanceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Substance Catalog')
                    ->description('Catalog constituents, whole ingredients, or groups. Market rules decide whether they are prohibited, restricted, or watch-only.')
                    ->icon(Heroicon::ShieldExclamation)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        Select::make('entity_type')
                            ->options([
                                'constituent' => 'Constituent',
                                'whole_ingredient' => 'Whole ingredient',
                                'group' => 'Group',
                            ])
                            ->default('constituent')
                            ->required(),
                        TextInput::make('inci_name')
                            ->label('INCI name')
                            ->maxLength(255),
                        Select::make('allergen_id')
                            ->label('Allergen link')
                            ->relationship(name: 'allergen', titleAttribute: 'inci_name')
                            ->searchable()
                            ->preload(),
                    ])
                    ->columns([
                        'md' => 2,
                    ]),
                Section::make('Identifiers')
                    ->description('Optional identifiers and alternate names used to reconcile supplier data.')
                    ->icon(Heroicon::Link)
                    ->schema([
                        TextInput::make('cas_number')
                            ->label('CAS number')
                            ->maxLength(255),
                        TextInput::make('ec_number')
                            ->label('EC number')
                            ->maxLength(255),
                        KeyValue::make('synonyms')
                            ->keyLabel('Alias')
                            ->valueLabel('Name')
                            ->columnSpanFull(),
                    ])
                    ->columns([
                        'md' => 2,
                    ]),
                Section::make('Source Traceability')
                    ->description('Keep the source visible so future regulatory updates can be audited.')
                    ->icon(Heroicon::DocumentText)
                    ->schema([
                        TextInput::make('source_name')
                            ->maxLength(255),
                        TextInput::make('source_url')
                            ->url()
                            ->maxLength(255),
                        Textarea::make('notes')
                            ->rows(4)
                            ->columnSpanFull(),
                        KeyValue::make('source_data')
                            ->keyLabel('Key')
                            ->valueLabel('Value')
                            ->columnSpanFull(),
                    ])
                    ->columns([
                        'md' => 2,
                    ]),
            ]);
    }
}
