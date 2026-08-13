<?php

namespace App\Filament\Resources\IngredientEnrichmentBatches\Tables;

use App\Enums\IngredientEnrichmentBatchStatus;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class IngredientEnrichmentBatchesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('public_id')->label(__('ingredient_enrichment_admin.fields.batch'))->limit(12)->copyable(),
                TextColumn::make('status')->badge()->formatStateUsing(fn ($state): string => $state->label()),
                TextColumn::make('progress')->state(fn ($record): string => ($record->total_count - $record->pending_count - $record->researching_count).' / '.$record->total_count),
                TextColumn::make('model')->badge(),
                TextColumn::make('requester.name'),
                TextColumn::make('input_tokens')->numeric(),
                TextColumn::make('output_tokens')->numeric(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(collect(IngredientEnrichmentBatchStatus::cases())->mapWithKeys(fn ($status): array => [$status->value => $status->label()])->all()),
            ])
            ->recordActions([
                ViewAction::make(),
            ])->defaultSort('created_at', 'desc');
    }
}
