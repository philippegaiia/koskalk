<?php

namespace App\Filament\Resources\IngredientEnrichmentBatches\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class IngredientEnrichmentBatchInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('ingredient_enrichment_admin.resource.summary'))->schema([
                    TextEntry::make('public_id')->copyable(),
                    TextEntry::make('status')->badge()->formatStateUsing(fn ($state): string => $state->label()),
                    TextEntry::make('model')->badge(),
                    TextEntry::make('reasoning_effort'),
                    IconEntry::make('fresh_research')
                        ->label(__('ingredient_enrichment_admin.fields.fresh_research'))
                        ->boolean(),
                    TextEntry::make('requester.name'),
                    TextEntry::make('total_count'),
                    TextEntry::make('ready_count'),
                    TextEntry::make('warning_count'),
                    TextEntry::make('failed_count'),
                    TextEntry::make('approved_count'),
                    TextEntry::make('rejected_count'),
                    TextEntry::make('applied_count'),
                    TextEntry::make('input_tokens')->numeric(),
                    TextEntry::make('output_tokens')->numeric(),
                    TextEntry::make('web_search_calls')->numeric(),
                    TextEntry::make('structured_source_calls')->numeric(),
                    TextEntry::make('started_at')->dateTime(),
                    TextEntry::make('completed_at')->dateTime(),
                ])->columns(4)->columnSpanFull(),
            ]);
    }
}
