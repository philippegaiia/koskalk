<?php

namespace App\Filament\Resources\IngredientSubstanceEntries\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class IngredientSubstanceEntriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['ingredient', 'substance']))
            ->columns([
                TextColumn::make('ingredient.display_name')
                    ->label('Ingredient')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('substance.name')
                    ->label('Substance')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('concentration_percent')
                    ->label('Concentration')
                    ->suffix('%')
                    ->placeholder('Unknown')
                    ->sortable(),
                TextColumn::make('concentration_source')
                    ->label('Source')
                    ->badge()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('concentration_source')
                    ->options([
                        'supplier' => 'Supplier',
                        'platform_estimate' => 'Platform estimate',
                        'inflated_safety' => 'Inflated safety value',
                        'unknown' => 'Unknown',
                    ]),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->defaultSort('ingredient_id')
            ->emptyStateHeading('No substance entries yet')
            ->emptyStateDescription('Link ingredients to constituents or whole-ingredient rules before evaluating formulas.')
            ->paginated([25, 50, 100]);
    }
}
