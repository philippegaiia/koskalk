<?php

namespace App\Filament\Resources\IngredientIntakeBatches\Schemas;

use App\Filament\Resources\IngredientEnrichmentBatches\IngredientEnrichmentBatchResource;
use App\Models\IngredientEnrichmentBatch;
use App\Models\IngredientIntakeBatch;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class IngredientIntakeBatchInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('ingredient_intake_admin.infolist.summary'))
                    ->schema([
                        TextEntry::make('public_id')->copyable(),
                        TextEntry::make('name'),
                        TextEntry::make('status')->badge()->formatStateUsing(fn ($state): string => $state->label()),
                        TextEntry::make('input_method')->formatStateUsing(fn ($state): string => $state->label()),
                        TextEntry::make('family_hint')
                            ->formatStateUsing(fn ($state): string => $state?->label() ?? __('ingredient_intake_admin.table.no_family')),
                        TextEntry::make('allow_gap_research')
                            ->formatStateUsing(fn (mixed $state): string => $state
                                ? __('ingredient_intake_admin.infolist.enabled')
                                : __('ingredient_intake_admin.infolist.disabled')),
                        TextEntry::make('total_count'),
                        TextEntry::make('ready_count'),
                        TextEntry::make('failed_count'),
                        TextEntry::make('approved_count'),
                        TextEntry::make('promoted_count'),
                        TextEntry::make('rejected_count'),
                        TextEntry::make('enrichmentBatch.public_id')
                            ->label(__('ingredient_intake_admin.infolist.enrichment_batch'))
                            ->url(fn (IngredientIntakeBatch $record): ?string => $record->enrichmentBatch instanceof IngredientEnrichmentBatch
                                ? IngredientEnrichmentBatchResource::getUrl('view', ['record' => $record->enrichmentBatch])
                                : null),
                        TextEntry::make('created_at')->dateTime(),
                        TextEntry::make('started_at')->dateTime(),
                        TextEntry::make('completed_at')->dateTime(),
                        TextEntry::make('notes')->columnSpanFull(),
                    ])
                    ->columns(4)
                    ->columnSpanFull(),
            ]);
    }
}
