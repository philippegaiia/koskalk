<?php

namespace App\Filament\Resources\IngredientSapProfiles\Tables;

use App\Models\IngredientSapProfile;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class IngredientSapProfilesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'ingredient' => fn ($ingredientQuery) => $ingredientQuery->withCount('fattyAcidEntries'),
            ]))
            ->columns([
                TextColumn::make('ingredient.display_name')
                    ->label('Ingredient')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('ingredient.category')
                    ->label('Category')
                    ->badge(),
                TextColumn::make('naoh_sap_value')
                    ->label('NaOH SAP')
                    ->numeric(decimalPlaces: 6),
                TextColumn::make('koh_sap_value')
                    ->label('KOH SAP')
                    ->numeric(decimalPlaces: 6)
                    ->sortable(),
                TextColumn::make('iodine_value')
                    ->label('Iodine')
                    ->numeric(decimalPlaces: 3)
                    ->sortable(),
                TextColumn::make('ins_value')
                    ->label('INS')
                    ->numeric(decimalPlaces: 3)
                    ->sortable(),
                IconColumn::make('has_fatty_acid_profile')
                    ->label('Fatty acids')
                    ->boolean()
                    ->state(fn (IngredientSapProfile $record): bool => (($record->ingredient?->fatty_acid_entries_count) ?? 0) > 0),
            ])
            ->filters([])
            ->recordActions([
                EditAction::make(),
            ])
            ->defaultSort('koh_sap_value', 'desc')
            ->emptyStateHeading('No SAP profiles yet')
            ->emptyStateDescription('Add KOH SAP, optional iodine and INS references, and notes after the ingredient exists.')
            ->paginated([25, 50, 100]);
    }
}
