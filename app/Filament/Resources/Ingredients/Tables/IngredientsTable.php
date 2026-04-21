<?php

namespace App\Filament\Resources\Ingredients\Tables;

use App\Filament\Exports\IngredientExporter;
use App\IngredientCategory;
use App\Models\Ingredient;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ExportBulkAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class IngredientsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('display_name')
                    ->label(__('Ingredient'))
                    ->searchable()
                    ->sortable()
                    ->description(fn (Ingredient $record): ?string => $record->inci_name)
                    ->wrap(),
                TextColumn::make('source_key')
                    ->label(__('Code'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('category')
                    ->badge()
                    ->sortable(),
                IconColumn::make('is_potentially_saponifiable')
                    ->label('Soap oil')
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
                    ->multiple()
                    ->options(IngredientCategory::class),
                TernaryFilter::make('is_potentially_saponifiable')
                    ->label('Trusted for soap saponification'),
                TernaryFilter::make('requires_admin_review')
                    ->label('Requires review'),
                TernaryFilter::make('is_active')
                    ->label('Active'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    ExportBulkAction::make()
                        ->exporter(IngredientExporter::class),
                ]),
            ])
            ->defaultSort('source_key')
            ->emptyStateHeading('No ingredients yet')
            ->emptyStateDescription('Seed the starter catalog or add a platform ingredient manually.')
            ->paginated([25, 50, 100]);
    }
}
