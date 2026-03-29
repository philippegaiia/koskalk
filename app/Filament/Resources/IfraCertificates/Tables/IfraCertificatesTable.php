<?php

namespace App\Filament\Resources\IfraCertificates\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class IfraCertificatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['ingredientVersion.ingredient'])->withCount('limits'))
            ->columns([
                TextColumn::make('certificate_name')
                    ->label('Current IFRA set')
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                TextColumn::make('ingredientVersion.display_name')
                    ->label('Ingredient')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('ifra_amendment')
                    ->label('Amendment')
                    ->sortable(),
                IconColumn::make('is_current')
                    ->label('Current')
                    ->boolean(),
                TextColumn::make('limits_count')
                    ->label('Category limits')
                    ->counts('limits')
                    ->sortable(),
                TextColumn::make('published_at')
                    ->label('Published')
                    ->date()
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_current')
                    ->label('Current'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->defaultSort('published_at', 'desc')
            ->emptyStateHeading('No IFRA sets yet')
            ->emptyStateDescription('Add the current IFRA amendment and category limits for aromatic materials here. Reference files can stay external.')
            ->paginated([25, 50, 100]);
    }
}
