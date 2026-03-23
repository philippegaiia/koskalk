<?php

namespace App\Filament\Resources\IngredientVersions\Tables;

use App\Models\IngredientVersion;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class IngredientVersionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['ingredient', 'sapProfile'])->withCount(['allergenEntries', 'ifraCertificates']))
            ->columns([
                TextColumn::make('display_name')
                    ->searchable()
                    ->sortable()
                    ->description(fn (IngredientVersion $record): ?string => $record->ingredient?->source_key),
                TextColumn::make('ingredient.category')
                    ->label('Category')
                    ->badge(),
                TextColumn::make('version')
                    ->sortable(),
                TextColumn::make('sapProfile.naoh_sap_value')
                    ->label('NaOH SAP')
                    ->numeric(decimalPlaces: 6),
                TextColumn::make('allergen_entries_count')
                    ->label('Allergens')
                    ->sortable(),
                TextColumn::make('ifra_certificates_count')
                    ->label('IFRA')
                    ->sortable(),
                IconColumn::make('is_current')
                    ->label('Current')
                    ->boolean(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                IconColumn::make('is_manufactured')
                    ->label('Made')
                    ->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_current')
                    ->label('Current version'),
                TernaryFilter::make('is_active')
                    ->label('Active'),
                TernaryFilter::make('is_manufactured')
                    ->label('Manufactured'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->defaultSort('display_name')
            ->emptyStateHeading('No ingredient versions yet')
            ->emptyStateDescription('Create a version when an ingredient needs legal naming, pricing, or traceability data.')
            ->paginated([25, 50, 100]);
    }
}
