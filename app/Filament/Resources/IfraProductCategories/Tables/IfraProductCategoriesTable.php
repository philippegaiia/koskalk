<?php

namespace App\Filament\Resources\IfraProductCategories\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class IfraProductCategoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount('productFamilies'))
            ->columns([
                TextColumn::make('code')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('short_name')
                    ->label('Short name')
                    ->toggleable(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                TextColumn::make('product_families_count')
                    ->label('Product families')
                    ->counts('productFamilies')
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->defaultSort('code')
            ->emptyStateHeading('No IFRA product categories yet')
            ->emptyStateDescription('Add the product categories your formulas will be evaluated against before attaching IFRA limits.')
            ->paginated([25, 50, 100]);
    }
}
