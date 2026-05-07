<?php

namespace App\Filament\Resources\IngredientSubstanceEntries\Schemas;

use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class IngredientSubstanceEntryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Ingredient Substance Composition')
                    ->description('Record factual composition for one ingredient. Market rules decide how it is evaluated later.')
                    ->icon(Heroicon::Beaker)
                    ->schema([
                        Select::make('ingredient_id')
                            ->relationship(name: 'ingredient', titleAttribute: 'display_name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Select::make('substance_id')
                            ->relationship(name: 'substance', titleAttribute: 'name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        TextInput::make('concentration_percent')
                            ->label('Concentration')
                            ->numeric()
                            ->inputMode('decimal')
                            ->suffix('%')
                            ->helperText('Leave empty when the ingredient is known to contain it but the concentration is unknown.'),
                        Select::make('concentration_source')
                            ->options([
                                'supplier' => 'Supplier',
                                'platform_estimate' => 'Platform estimate',
                                'inflated_safety' => 'Inflated safety value',
                                'unknown' => 'Unknown',
                            ])
                            ->default('unknown')
                            ->required(),
                    ])
                    ->columns([
                        'md' => 2,
                    ]),
                Section::make('Source Traceability')
                    ->description('Keep supplier or platform notes beside the concentration used for compliance checks.')
                    ->icon(Heroicon::DocumentText)
                    ->schema([
                        Textarea::make('source_notes')
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
