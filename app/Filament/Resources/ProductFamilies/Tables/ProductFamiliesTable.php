<?php

namespace App\Filament\Resources\ProductFamilies\Tables;

use App\Models\ProductFamily;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ProductFamiliesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->description(fn (ProductFamily $record): string => $record->slug),
                TextColumn::make('calculation_basis')
                    ->label('Calculation basis'),
                TextColumn::make('cured_water_percentage')
                    ->label('Residual water')
                    ->state(fn (ProductFamily $record): ?string => $record->slug === 'soap'
                        ? rtrim(rtrim(number_format($record->curedWaterPercentage(), 2, '.', ''), '0'), '.').'%'
                        : null)
                    ->placeholder('—'),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
