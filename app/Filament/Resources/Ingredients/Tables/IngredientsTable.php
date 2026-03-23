<?php

namespace App\Filament\Resources\Ingredients\Tables;

use App\IngredientCategory;
use App\Models\Ingredient;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class IngredientsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('currentVersion'))
            ->columns([
                TextColumn::make('currentVersion.display_name')
                    ->label('Display name')
                    ->description(fn (Ingredient $record): ?string => $record->currentVersion?->inci_name)
                    ->wrap(),
                TextColumn::make('source_key')
                    ->label('Source key')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('category')
                    ->badge()
                    ->sortable(),
                IconColumn::make('is_potentially_saponifiable')
                    ->label('Soap calc')
                    ->boolean(),
                IconColumn::make('requires_admin_review')
                    ->label('Review')
                    ->boolean(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                TextColumn::make('updated_at')
                    ->label('Updated')
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->options(IngredientCategory::class),
                TernaryFilter::make('is_potentially_saponifiable')
                    ->label('Potentially saponifiable'),
                TernaryFilter::make('requires_admin_review')
                    ->label('Requires review'),
                TernaryFilter::make('is_active')
                    ->label('Active'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->defaultSort('source_key')
            ->emptyStateHeading('No ingredients yet')
            ->emptyStateDescription('Seed the starter catalog or add a platform ingredient manually.')
            ->paginated([25, 50, 100]);
    }
}
