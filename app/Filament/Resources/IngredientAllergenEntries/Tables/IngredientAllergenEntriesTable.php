<?php

namespace App\Filament\Resources\IngredientAllergenEntries\Tables;

use App\Models\IngredientAllergenEntry;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class IngredientAllergenEntriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['ingredientVersion.ingredient', 'allergen']))
            ->columns([
                TextColumn::make('ingredientVersion.display_name')
                    ->label('Ingredient version')
                    ->description(fn (IngredientAllergenEntry $record): ?string => $record->ingredientVersion?->ingredient?->category?->getLabel())
                    ->searchable()
                    ->sortable(),
                TextColumn::make('allergen.inci_name')
                    ->label('Allergen')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('concentration_percent')
                    ->label('Concentration')
                    ->numeric(decimalPlaces: 5)
                    ->suffix('%')
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label('Updated')
                    ->since()
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->defaultSort('updated_at', 'desc')
            ->emptyStateHeading('No allergen composition entries yet')
            ->emptyStateDescription('Add allergen percentages to essential oils, aromatic extracts, and other compliance-sensitive materials.')
            ->paginated([25, 50, 100]);
    }
}
