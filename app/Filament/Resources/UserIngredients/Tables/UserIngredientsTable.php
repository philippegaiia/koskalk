<?php

namespace App\Filament\Resources\UserIngredients\Tables;

use App\IngredientCategory;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class UserIngredientsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('display_name')
                    ->label('Ingredient')
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                TextColumn::make('inci_name')
                    ->label('INCI')
                    ->placeholder('Not provided')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('category')
                    ->badge()
                    ->sortable(),
                TextColumn::make('supplier_name')
                    ->label('Supplier')
                    ->placeholder('Not provided')
                    ->searchable()
                    ->wrap(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                TextColumn::make('created_at')
                    ->label('Added')
                    ->date()
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label('Updated')
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->multiple()
                    ->options(IngredientCategory::class),
                Filter::make('missing_inci')
                    ->label('Missing INCI')
                    ->query(fn (Builder $query): Builder => $query->where(
                        fn (Builder $query): Builder => $query
                            ->whereNull('inci_name')
                            ->orWhere('inci_name', ''),
                    )),
                TernaryFilter::make('is_active')
                    ->label('Active'),
            ])
            ->recordActions([])
            ->toolbarActions([])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No user ingredients yet')
            ->emptyStateDescription('Anonymous user-created ingredients will appear here for catalog review.')
            ->paginated([25, 50, 100]);
    }
}
