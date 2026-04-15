<?php

namespace App\Filament\Resources\ProductTypes\Tables;

use App\Models\ProductType;
use App\Services\MediaStorage;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProductTypesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->withCount(['recipes as recipes_count' => fn (Builder $query): Builder => $query->withoutGlobalScopes()])
                ->with(['productFamily', 'defaultIfraProductCategory']))
            ->columns([
                ImageColumn::make('fallback_image_path')
                    ->label('Image')
                    ->disk(MediaStorage::publicDisk())
                    ->visibility(MediaStorage::publicVisibility())
                    ->square()
                    ->imageSize(52)
                    ->checkFileExistence(false),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->description(fn (ProductType $record): string => $record->slug),
                TextColumn::make('productFamily.name')
                    ->label('Family')
                    ->badge()
                    ->sortable(),
                TextColumn::make('defaultIfraProductCategory.code')
                    ->label('Default IFRA')
                    ->placeholder('None')
                    ->sortable(),
                TextColumn::make('sort_order')
                    ->label('Sort')
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                TextColumn::make('recipes_count')
                    ->label('Recipes')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('product_family_id')
                    ->label('Product family')
                    ->relationship(name: 'productFamily', titleAttribute: 'name')
                    ->searchable()
                    ->preload(),
                TernaryFilter::make('is_active')
                    ->label('Active'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->defaultSort('sort_order')
            ->emptyStateHeading('No product types yet')
            ->emptyStateDescription('Add platform-managed cosmetic categories for recipe cards, filters, defaults, and fallback images.')
            ->paginated([25, 50, 100]);
    }
}
