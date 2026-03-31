<?php

namespace App\Filament\Resources\FattyAcids\Tables;

use App\Filament\Exports\FattyAcidExporter;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ExportBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class FattyAcidsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('key')
                    ->label(__('Key'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('short_name')
                    ->label(__('Short name'))
                    ->searchable(),
                TextColumn::make('chain_length')
                    ->label(__('C'))
                    ->sortable(),
                TextColumn::make('double_bonds')
                    ->label(__('DB'))
                    ->sortable(),
                TextColumn::make('saturation_class')
                    ->label(__('Saturation'))
                    ->badge()
                    ->sortable(),
                TextColumn::make('iodine_factor')
                    ->label(__('Iodine factor'))
                    ->numeric(decimalPlaces: 3)
                    ->sortable(),
                TextColumn::make('display_order')
                    ->label(__('Order'))
                    ->sortable(),
                IconColumn::make('is_core')
                    ->label('Core')
                    ->boolean(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_core')
                    ->label('Core'),
                TernaryFilter::make('is_active')
                    ->label('Active'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    ExportBulkAction::make()
                        ->exporter(FattyAcidExporter::class),
                ]),
            ])
            ->defaultSort('display_order')
            ->emptyStateHeading('No fatty acids yet')
            ->emptyStateDescription('Seed the starter catalog or add fatty acids manually.')
            ->paginated([25, 50, 100]);
    }
}
