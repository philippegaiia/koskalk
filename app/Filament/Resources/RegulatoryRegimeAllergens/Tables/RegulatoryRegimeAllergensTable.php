<?php

namespace App\Filament\Resources\RegulatoryRegimeAllergens\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class RegulatoryRegimeAllergensTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['regulatoryRegime', 'allergen']))
            ->columns([
                TextColumn::make('regulatoryRegime.name')
                    ->label('Regime')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('allergen.inci_name')
                    ->label('Allergen')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('declaration_label')
                    ->label('Declaration label')
                    ->placeholder('Catalog INCI')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('rinse_off_threshold_percent')
                    ->label('Rinse-off')
                    ->suffix('%')
                    ->sortable(),
                TextColumn::make('leave_on_threshold_percent')
                    ->label('Leave-on')
                    ->suffix('%')
                    ->sortable(),
                TextColumn::make('threshold_operator')
                    ->label('Operator')
                    ->toggleable(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('regulatory_regime_id')
                    ->label('Regime')
                    ->relationship(name: 'regulatoryRegime', titleAttribute: 'name')
                    ->searchable()
                    ->preload(),
                TernaryFilter::make('is_active')
                    ->label('Active'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->defaultSort('regulatory_regime_id')
            ->emptyStateHeading('No regime allergen rules yet')
            ->emptyStateDescription('Map allergens to regulatory regimes so formulas declare only the selected market list.')
            ->paginated([25, 50, 100]);
    }
}
