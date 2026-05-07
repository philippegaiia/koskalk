<?php

namespace App\Filament\Resources\RegulatoryRegimeAllergens\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class RegulatoryRegimeAllergenForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Regime Rule')
                    ->description('Map a factual allergen from the reference catalog to the selected market regime.')
                    ->icon(Heroicon::Sparkles)
                    ->schema([
                        Select::make('regulatory_regime_id')
                            ->relationship(name: 'regulatoryRegime', titleAttribute: 'name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Select::make('allergen_id')
                            ->relationship(name: 'allergen', titleAttribute: 'inci_name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        TextInput::make('declaration_label')
                            ->helperText('Leave blank to use the allergen INCI name from the catalog.')
                            ->maxLength(255),
                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true),
                    ])
                    ->columns([
                        'md' => 2,
                    ]),
                Section::make('Thresholds')
                    ->description('Thresholds are percentages of finished formula for the selected exposure mode.')
                    ->icon(Heroicon::Beaker)
                    ->schema([
                        TextInput::make('rinse_off_threshold_percent')
                            ->label('Rinse-off threshold')
                            ->numeric()
                            ->inputMode('decimal')
                            ->suffix('%')
                            ->default(0.01)
                            ->required(),
                        TextInput::make('leave_on_threshold_percent')
                            ->label('Leave-on threshold')
                            ->numeric()
                            ->inputMode('decimal')
                            ->suffix('%')
                            ->default(0.001)
                            ->required(),
                        Select::make('threshold_operator')
                            ->options([
                                'greater_than_or_equal' => 'Greater than or equal',
                                'greater_than' => 'Greater than',
                            ])
                            ->default('greater_than_or_equal')
                            ->required(),
                    ])
                    ->columns([
                        'md' => 3,
                    ]),
                Section::make('Grouping And Effective Window')
                    ->description('Optional grouping lets a regime declare a different printed label while preserving the catalog allergen entry.')
                    ->icon(Heroicon::Link)
                    ->schema([
                        TextInput::make('group_key')
                            ->maxLength(255),
                        TextInput::make('group_label')
                            ->maxLength(255),
                        DatePicker::make('effective_from'),
                        DatePicker::make('effective_until'),
                    ])
                    ->columns([
                        'md' => 2,
                    ]),
                Section::make('Source Traceability')
                    ->description('Record the legal reference or internal admin note behind this declaration rule.')
                    ->icon(Heroicon::DocumentText)
                    ->schema([
                        Textarea::make('source_reference')
                            ->rows(4)
                            ->columnSpanFull(),
                        KeyValue::make('source_data')
                            ->keyLabel('Key')
                            ->valueLabel('Value')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
