<?php

namespace App\Filament\Resources\RegulatoryRegimes\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class RegulatoryRegimeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Regime Identity')
                    ->description('Define the market rule set that a formula can select for allergen declaration screening.')
                    ->icon(Heroicon::DocumentCheck)
                    ->schema([
                        TextInput::make('code')
                            ->helperText('Stable lowercase key used by saved formulas, for example eu, us_mocra_preview, or canada.')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(64),
                        TextInput::make('market_code')
                            ->label('Market')
                            ->helperText('Short market code such as EU, US, or CA.')
                            ->required()
                            ->maxLength(16),
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('version_label')
                            ->helperText('Human label for the legal/source version this regime follows.')
                            ->maxLength(255),
                        Select::make('status')
                            ->options([
                                'active' => 'Active',
                                'preview' => 'Preview',
                                'retired' => 'Retired',
                            ])
                            ->default('active')
                            ->required(),
                        Toggle::make('is_default')
                            ->label('Default regime')
                            ->default(false),
                    ])
                    ->columns([
                        'md' => 2,
                    ]),
                Section::make('Effective Window')
                    ->description('Use dates to prepare future changes without applying them before they become relevant.')
                    ->icon(Heroicon::CalendarDays)
                    ->schema([
                        DatePicker::make('effective_from'),
                        DatePicker::make('effective_until'),
                        DateTimePicker::make('reviewed_at')
                            ->seconds(false),
                    ])
                    ->columns([
                        'md' => 3,
                    ]),
                Section::make('Source Traceability')
                    ->description('Keep the legal or stewardship source visible so admin updates remain auditable.')
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
