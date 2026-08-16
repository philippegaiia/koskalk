<?php

namespace App\Filament\Resources\IngredientEnrichmentBatches\RelationManagers;

use App\Actions\IngredientEnrichment\ApproveIngredientEnrichmentItem;
use App\Actions\IngredientEnrichment\EditIngredientEnrichmentProposal;
use App\Actions\IngredientEnrichment\RejectIngredientEnrichmentItem;
use App\Enums\IngredientEnrichmentItemStatus;
use App\Filament\Resources\IngredientEnrichmentBatches\Schemas\IngredientEnrichmentProposalForm;
use App\Models\IngredientEnrichmentBatchItem;
use App\Services\IngredientEnrichment\IngredientEnrichmentApprovalPresenter;
use App\Services\IngredientEnrichment\IngredientEnrichmentReviewPresenter;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static bool $isLazy = false;

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('subject')
                ->label(__('ingredient_enrichment_admin.fields.subject'))
                ->state(fn (IngredientEnrichmentBatchItem $record): string => app(IngredientEnrichmentReviewPresenter::class)->subjectLabel($record)),
            TextEntry::make('status')->badge()->formatStateUsing(fn ($state): string => $state->label()),
            TextEntry::make('failure_code')
                ->label(__('ingredient_enrichment_admin.fields.failure_code'))
                ->badge()
                ->visible(fn (IngredientEnrichmentBatchItem $record): bool => filled($record->failure_code)),
            TextEntry::make('failure_message')
                ->label(__('ingredient_enrichment_admin.fields.failure_message'))
                ->visible(fn (IngredientEnrichmentBatchItem $record): bool => filled($record->failure_message))
                ->columnSpanFull(),
            TextEntry::make('rejection_reason')
                ->label(__('ingredient_enrichment_admin.form.rejection_reason'))
                ->visible(fn (IngredientEnrichmentBatchItem $record): bool => filled($record->rejection_reason))
                ->columnSpanFull(),
            TextEntry::make('confidence')->badge(),
            TextEntry::make('warnings')->listWithLineBreaks(),
            TextEntry::make('unresolved_questions')->listWithLineBreaks(),
            ViewEntry::make('review')
                ->hiddenLabel()
                ->state(fn (IngredientEnrichmentBatchItem $record): array => [
                    'rows' => app(IngredientEnrichmentReviewPresenter::class)->rows($record),
                    'sources' => $record->sources ?? [],
                ])
                ->view('filament.ingredient-enrichment.review-fields')
                ->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->recordTitleAttribute('catalog_key')
            ->poll(fn (): ?string => $this->getOwnerRecord()->status->isTerminal() ? null : '5s')
            ->columns([
                TextColumn::make('subject')
                    ->label(__('ingredient_enrichment_admin.fields.subject'))
                    ->state(fn (IngredientEnrichmentBatchItem $record): string => app(IngredientEnrichmentReviewPresenter::class)->subjectLabel($record))
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->where(function (Builder $query) use ($search): void {
                            $query->where('catalog_key', 'like', "%{$search}%")
                                ->orWhereHas('intakeItem', fn (Builder $query): Builder => $query
                                    ->where('original_current_name', 'like', "%{$search}%")
                                    ->orWhere('original_inci_name', 'like', "%{$search}%"));
                        });
                    }),
                TextColumn::make('status')->badge()->formatStateUsing(fn ($state): string => $state->label()),
                TextColumn::make('failure_code')
                    ->label(__('ingredient_enrichment_admin.fields.failure_code'))
                    ->badge()
                    ->visible(fn (): bool => $this->getOwnerRecord()->failed_count > 0),
                TextColumn::make('failure_message')
                    ->label(__('ingredient_enrichment_admin.fields.failure_message'))
                    ->wrap()
                    ->visible(fn (): bool => $this->getOwnerRecord()->failed_count > 0),
                TextColumn::make('confidence')->badge(),
                TextColumn::make('warnings')->state(fn (IngredientEnrichmentBatchItem $record): int => count($record->warnings ?? []))->badge(),
                TextColumn::make('sources')->state(fn (IngredientEnrichmentBatchItem $record): int => count($record->sources ?? []))->badge(),
                TextColumn::make('structured_source_calls')->numeric(),
                TextColumn::make('input_tokens')->numeric(),
                TextColumn::make('output_tokens')->numeric(),
            ])
            ->recordActions([
                ViewAction::make()->label(__('ingredient_enrichment_admin.actions.review')),
                Action::make('editProposal')
                    ->label(__('ingredient_enrichment_admin.actions.edit'))
                    ->icon('heroicon-o-pencil-square')
                    ->visible(fn (IngredientEnrichmentBatchItem $record): bool => in_array($record->status, [
                        IngredientEnrichmentItemStatus::Ready,
                        IngredientEnrichmentItemStatus::Warning,
                        IngredientEnrichmentItemStatus::Approved,
                        IngredientEnrichmentItemStatus::Rejected,
                    ], true))
                    ->fillForm(fn (IngredientEnrichmentBatchItem $record): array => is_array(data_get($record->result, 'proposal'))
                        ? data_get($record->result, 'proposal')
                        : [])
                    ->schema(IngredientEnrichmentProposalForm::schema())
                    ->successNotificationTitle(__('ingredient_enrichment_admin.notifications.edited'))
                    ->action(fn (IngredientEnrichmentBatchItem $record, array $data, EditIngredientEnrichmentProposal $editProposal) => $editProposal->handle(auth()->user(), $record, $data)),
                Action::make('approve')
                    ->label(__('ingredient_enrichment_admin.actions.approve'))
                    ->icon('heroicon-o-check')
                    ->visible(fn (IngredientEnrichmentBatchItem $record): bool => in_array($record->status, [IngredientEnrichmentItemStatus::Ready, IngredientEnrichmentItemStatus::Warning], true))
                    ->schema(function (IngredientEnrichmentBatchItem $record): array {
                        $conflicts = app(IngredientEnrichmentApprovalPresenter::class)->replacementConflicts($record);

                        if ($conflicts === []) {
                            return [Text::make(__('ingredient_enrichment_admin.approval.no_conflicts'))];
                        }

                        return [
                            Text::make(__('ingredient_enrichment_admin.approval.conflicts_intro')),
                            CheckboxList::make('replace_fields')
                                ->options(collect($conflicts)->mapWithKeys(
                                    fn (array $conflict, string $field): array => [$field => $conflict['label']],
                                )->all())
                                ->descriptions(collect($conflicts)->mapWithKeys(
                                    fn (array $conflict, string $field): array => [$field => $conflict['description']],
                                )->all()),
                        ];
                    })
                    ->successNotificationTitle(__('ingredient_enrichment_admin.notifications.approved'))
                    ->action(fn (IngredientEnrichmentBatchItem $record, array $data, ApproveIngredientEnrichmentItem $approveItem) => $approveItem->handle(auth()->user(), $record, $data['replace_fields'] ?? [])),
                Action::make('reject')
                    ->label(__('ingredient_enrichment_admin.actions.reject'))
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->visible(fn (IngredientEnrichmentBatchItem $record): bool => in_array($record->status, [IngredientEnrichmentItemStatus::Ready, IngredientEnrichmentItemStatus::Warning], true))
                    ->schema([
                        Textarea::make('reason')
                            ->label(__('ingredient_enrichment_admin.form.rejection_reason'))
                            ->required()
                            ->maxLength(2000),
                    ])
                    ->successNotificationTitle(__('ingredient_enrichment_admin.notifications.rejected'))
                    ->action(fn (IngredientEnrichmentBatchItem $record, array $data, RejectIngredientEnrichmentItem $rejectItem) => $rejectItem->handle(auth()->user(), $record, $data['reason'] ?? null)),
            ])
            ->defaultSort('id');
    }
}
