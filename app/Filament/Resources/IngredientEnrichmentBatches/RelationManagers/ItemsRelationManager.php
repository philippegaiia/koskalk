<?php

namespace App\Filament\Resources\IngredientEnrichmentBatches\RelationManagers;

use App\Actions\IngredientEnrichment\ApproveIngredientEnrichmentItem;
use App\Enums\IngredientEnrichmentItemStatus;
use App\Enums\IngredientEnrichmentReplaceField;
use App\Models\IngredientEnrichmentBatchItem;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static bool $isLazy = false;

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('catalog_key'),
            TextEntry::make('status')->badge()->formatStateUsing(fn ($state): string => $state->label()),
            TextEntry::make('confidence')->badge(),
            TextEntry::make('warnings')->listWithLineBreaks(),
            TextEntry::make('unresolved_questions')->listWithLineBreaks(),
            KeyValueEntry::make('plan.effective.canonical')->columnSpanFull(),
            TextEntry::make('result.proposal.info_markdown')->markdown()->columnSpanFull(),
            KeyValueEntry::make('result.proposal.identifiers')->columnSpanFull(),
            KeyValueEntry::make('result.proposal.cosing_functions')->columnSpanFull(),
            KeyValueEntry::make('result.proposal.translations')->columnSpanFull(),
            KeyValueEntry::make('result.proposal.market_labels')->columnSpanFull(),
            KeyValueEntry::make('sources')->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->recordTitleAttribute('catalog_key')
            ->poll(fn (): ?string => $this->getOwnerRecord()->status->isTerminal() ? null : '5s')
            ->columns([
                TextColumn::make('catalog_key')->searchable(),
                TextColumn::make('status')->badge()->formatStateUsing(fn ($state): string => $state->label()),
                TextColumn::make('confidence')->badge(),
                TextColumn::make('warnings')->state(fn (IngredientEnrichmentBatchItem $record): int => count($record->warnings ?? []))->badge(),
                TextColumn::make('sources')->state(fn (IngredientEnrichmentBatchItem $record): int => count($record->sources ?? []))->badge(),
                TextColumn::make('input_tokens')->numeric(),
                TextColumn::make('output_tokens')->numeric(),
            ])
            ->recordActions([
                ViewAction::make()->label(__('ingredient_enrichment_admin.actions.review')),
                Action::make('approve')
                    ->label(__('ingredient_enrichment_admin.actions.approve'))
                    ->icon('heroicon-o-check')
                    ->visible(fn (IngredientEnrichmentBatchItem $record): bool => in_array($record->status, [IngredientEnrichmentItemStatus::Ready, IngredientEnrichmentItemStatus::Warning], true))
                    ->schema([CheckboxList::make('replace_fields')->options(IngredientEnrichmentReplaceField::options())])
                    ->action(fn (IngredientEnrichmentBatchItem $record, array $data, ApproveIngredientEnrichmentItem $approveItem) => $approveItem->handle(auth()->user(), $record, $data['replace_fields'] ?? [])),
            ])
            ->defaultSort('id');
    }
}
