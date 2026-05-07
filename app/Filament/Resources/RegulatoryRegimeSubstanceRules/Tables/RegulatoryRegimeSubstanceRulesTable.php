<?php

namespace App\Filament\Resources\RegulatoryRegimeSubstanceRules\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class RegulatoryRegimeSubstanceRulesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['regulatoryRegime', 'substance']))
            ->columns([
                TextColumn::make('regulatoryRegime.name')
                    ->label('Regime')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('substance.name')
                    ->label('Substance')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('rule_type')
                    ->label('Rule')
                    ->badge()
                    ->sortable(),
                TextColumn::make('rinse_off_max_percent')
                    ->label('Rinse-off max')
                    ->suffix('%')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('leave_on_max_percent')
                    ->label('Leave-on max')
                    ->suffix('%')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('exposure_scope')
                    ->label('Scope')
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
                SelectFilter::make('rule_type')
                    ->options([
                        'prohibited' => 'Prohibited',
                        'restricted' => 'Restricted',
                        'watch' => 'Watch',
                    ]),
                TernaryFilter::make('is_active')
                    ->label('Active'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->defaultSort('regulatory_regime_id')
            ->emptyStateHeading('No regime substance rules yet')
            ->emptyStateDescription('Map substances to regimes before formulas can be screened.')
            ->paginated([25, 50, 100]);
    }
}
