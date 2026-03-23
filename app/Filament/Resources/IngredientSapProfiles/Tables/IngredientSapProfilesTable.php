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
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['ingredientVersion.ingredient']))
            ->columns([
                TextColumn::make('ingredientVersion.display_name')
                    ->label('Ingredient version')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('ingredientVersion.ingredient.category')
                    ->label('Category')
                    ->badge(),
                TextColumn::make('naoh_sap_value')
                    ->label('NaOH SAP')
                    ->numeric(decimalPlaces: 6),
                TextColumn::make('koh_sap_value')
                    ->label('KOH SAP')
                    ->numeric(decimalPlaces: 6)
                    ->sortable(),
                IconColumn::make('has_fatty_acid_profile')
                    ->label('Fatty acids')
                    ->boolean()
                    ->state(fn (IngredientSapProfile $record): bool => $record->hasFattyAcidProfile()),
            ])
            ->filters([])
            ->recordActions([
                EditAction::make(),
            ])
            ->defaultSort('koh_sap_value', 'desc')
            ->emptyStateHeading('No SAP profiles yet')
            ->emptyStateDescription('Add KOH SAP and the core fatty-acid data after the ingredient version exists.')
            ->paginated([25, 50, 100]);
    }
}
