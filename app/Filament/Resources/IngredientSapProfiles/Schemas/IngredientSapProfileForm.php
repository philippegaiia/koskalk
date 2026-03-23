<?php

namespace App\Filament\Resources\IngredientSapProfiles\Schemas;

use App\SoapFattyAcid;
use App\SoapSap;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class IngredientSapProfileForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Saponification Data')
                    ->description('KOH SAP is the single stored source value. NaOH SAP is always derived from the fixed 0.713 ratio.')
                    ->icon(Heroicon::Beaker)
                    ->schema([
                        Select::make('ingredient_version_id')
                            ->relationship(name: 'ingredientVersion', titleAttribute: 'display_name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        TextInput::make('koh_sap_value')
                            ->label('KOH SAP')
                            ->numeric()
                            ->inputMode('decimal')
                            ->required()
                            ->live(onBlur: true)
                            ->dehydrateStateUsing(fn ($state): ?float => $state === null || $state === ''
                                ? null
                                : SoapSap::normalizeKohSapInput((float) $state))
                            ->helperText('You can enter professional-style KOH SAP like 245 or decimal-style 0.245. NaOH SAP is derived automatically.'),
                        Placeholder::make('naoh_sap_value')
                            ->label('Derived NaOH SAP')
                            ->content(fn (Get $get, $record): ?string => ($get('koh_sap_value') === null || $get('koh_sap_value') === '')
                                ? ($record?->naoh_sap_value === null
                                    ? null
                                    : number_format((float) $record->naoh_sap_value, 6, '.', ''))
                                : number_format(SoapSap::deriveNaohFromKoh((float) $get('koh_sap_value')), 6, '.', '')),
                    ])
                    ->columns([
                        'md' => 3,
                    ]),
                Section::make('Fatty Acid Profile')
                    ->description('Carrier oils use a fixed core fatty-acid set so calculations stay consistent and comparable.')
                    ->icon(Heroicon::ChartPie)
                    ->schema([
                        ...collect(SoapFattyAcid::coreSet())
                            ->map(fn (SoapFattyAcid $fattyAcid): TextInput => TextInput::make($fattyAcid->value)
                                ->label($fattyAcid->getLabel())
                                ->numeric()
                                ->inputMode('decimal')
                                ->suffix('%'))
                            ->all(),
                    ]),
                Section::make('Calculation Notes')
                    ->description('Soap qualities are derived from the stored KOH SAP and fixed fatty-acid inputs, not entered manually.')
                    ->icon(Heroicon::ClipboardDocumentList)
                    ->schema([
                        Textarea::make('source_notes')
                            ->rows(4)
                            ->columnSpanFull(),
                    ])
                    ->columns([
                        'md' => 2,
                    ]),
            ]);
    }
}
