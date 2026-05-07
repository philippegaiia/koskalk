<?php

namespace App\Filament\Resources\Substances\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SubstancesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('allergen'))
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('entity_type')
                    ->label('Type')
                    ->badge()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('inci_name')
                    ->label('INCI')
                    ->placeholder('—')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('allergen.inci_name')
                    ->label('Allergen link')
                    ->placeholder('—')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('cas_number')
                    ->label('CAS')
                    ->placeholder('—')
                    ->searchable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('entity_type')
                    ->options([
                        'constituent' => 'Constituent',
                        'whole_ingredient' => 'Whole ingredient',
                        'group' => 'Group',
                    ]),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->defaultSort('name')
            ->emptyStateHeading('No substances yet')
            ->emptyStateDescription('Add constituents, whole ingredients, or groups before mapping market rules.')
            ->paginated([25, 50, 100]);
    }
}
