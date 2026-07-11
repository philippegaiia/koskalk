<?php

namespace App\Filament\Resources\SupportedLocales\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class SupportedLocalesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Language')
                    ->description(fn ($record): string => $record->native_name)
                    ->searchable(['name', 'native_name'])
                    ->sortable(),
                TextColumn::make('code')
                    ->label('Locale')
                    ->badge()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('number_locale')
                    ->label('Number locale')
                    ->sortable(),
                TextColumn::make('text_direction')
                    ->label('Direction')
                    ->badge(),
                IconColumn::make('is_active')
                    ->label('Available')
                    ->boolean(),
                IconColumn::make('is_default')
                    ->label('Default')
                    ->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Available to users'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->defaultSort('sort_order')
            ->emptyStateHeading('No languages configured')
            ->emptyStateDescription('Add the default English locale before creating interface translations.');
    }
}
