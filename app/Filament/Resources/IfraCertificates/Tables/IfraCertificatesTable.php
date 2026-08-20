<?php

namespace App\Filament\Resources\IfraCertificates\Tables;

use App\Models\IfraCertificate;
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
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['ingredient', 'ifraAmendment'])->withCount('limits'))
            ->columns([
                TextColumn::make('certificate_name')
                    ->label('Current IFRA set')
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                TextColumn::make('ingredient.display_name')
                    ->label('Ingredient')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('ifra_amendment_display')
                    ->label('Amendment')
                    ->state(fn (IfraCertificate $record): ?string => $record->ifraAmendment?->code ?? $record->source_amendment_label ?? $record->ifra_amendment)
                    ->description(fn (IfraCertificate $record): ?string => filled($record->source_amendment_label)
                        && $record->source_amendment_label !== $record->ifraAmendment?->code
                            ? 'Source: '.$record->source_amendment_label
                            : null),
                TextColumn::make('peroxide_value')
                    ->label('Peroxide')
                    ->numeric(decimalPlaces: 3)
                    ->suffix(' meq O2/kg')
                    ->toggleable(),
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
