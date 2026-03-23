<?php

namespace App\Filament\Resources\Allergens\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AllergensTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount('ingredientEntries'))
            ->columns([
                TextColumn::make('inci_name')
                    ->label('INCI')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('cas_number')
                    ->label('CAS')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('ec_number')
                    ->label('EC')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('common_name_en')
                    ->label('English name')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('ingredient_entries_count')
                    ->label('Ingredient entries')
                    ->counts('ingredientEntries')
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label('Updated')
                    ->since()
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->emptyStateHeading('No allergens yet')
            ->emptyStateDescription('Seed the allergen catalog first, then add manual reference entries only when needed.')
            ->defaultSort('inci_name')
            ->paginated([25, 50, 100]);
    }
}
