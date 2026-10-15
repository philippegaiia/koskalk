<?php

namespace App\Filament\Resources\ProductFamilies\Schemas;

use App\Models\ProductFamily;
use App\SoapSap;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProductFamilyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Family')
                    ->schema([
                        TextInput::make('name')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('slug')
                            ->disabled()
                            ->dehydrated(false),
                    ])
                    ->columns(2),
                Section::make('Soap calculation defaults')
                    ->description('Applies to new formulas. Saved formula versions keep the value they were calculated with.')
                    ->visible(fn (?ProductFamily $record): bool => $record?->slug === 'soap')
                    ->schema([
                        TextInput::make('cured_water_percentage')
                            ->label('Residual water in cured soap')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(SoapSap::MAX_CURED_WATER_PERCENTAGE)
                            ->step(0.01)
                            ->suffix('%')
                            ->placeholder((string) SoapSap::DEFAULT_CURED_WATER_PERCENTAGE)
                            ->helperText('Used for cured weight and cured-basis label percentages. Leave empty to use '.SoapSap::DEFAULT_CURED_WATER_PERCENTAGE.'%.'),
                    ]),
            ]);
    }
}
