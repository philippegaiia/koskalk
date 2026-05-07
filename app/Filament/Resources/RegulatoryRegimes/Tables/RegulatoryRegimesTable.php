<?php

namespace App\Filament\Resources\RegulatoryRegimes\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class RegulatoryRegimesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount('allergenRules'))
            ->columns([
                TextColumn::make('code')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('market_code')
                    ->label('Market')
                    ->badge()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('version_label')
                    ->label('Version')
                    ->toggleable(),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                IconColumn::make('is_default')
                    ->label('Default')
                    ->boolean(),
                TextColumn::make('allergen_rules_count')
                    ->label('Allergen rules')
                    ->counts('allergenRules')
                    ->sortable(),
                TextColumn::make('reviewed_at')
                    ->label('Reviewed')
                    ->date()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'active' => 'Active',
                        'preview' => 'Preview',
                        'retired' => 'Retired',
                    ]),
                TernaryFilter::make('is_default')
                    ->label('Default regime'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->defaultSort('code')
            ->emptyStateHeading('No regulatory regimes yet')
            ->emptyStateDescription('Add a market regime, then map declarable allergen rules to it.')
            ->paginated([25, 50, 100]);
    }
}
