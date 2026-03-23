<?php

namespace App\Filament\Resources\IfraCertificates\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
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
                    ->label('Certificate')
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                TextColumn::make('ingredientVersion.display_name')
                    ->label('Ingredient version')
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
            ->recordActions([
                EditAction::make(),
            ])
            ->defaultSort('published_at', 'desc')
            ->emptyStateHeading('No IFRA certificates yet')
            ->emptyStateDescription('Add certificate versions for aromatic materials and capture their per-category limits here.')
            ->paginated([25, 50, 100]);
    }
}
