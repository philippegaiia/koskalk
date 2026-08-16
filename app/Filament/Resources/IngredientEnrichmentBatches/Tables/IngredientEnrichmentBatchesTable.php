<?php

namespace App\Filament\Resources\IngredientEnrichmentBatches\Tables;

use App\Actions\IngredientEnrichment\DeleteIngredientEnrichmentBatch as DeleteIngredientEnrichmentBatchAction;
use App\Enums\IngredientEnrichmentBatchStatus;
use App\Models\IngredientEnrichmentBatch;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;
use Throwable;

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
                Action::make('delete')
                    ->label(__('ingredient_enrichment_admin.actions.delete'))
                    ->color('danger')
                    ->icon(Heroicon::Trash)
                    ->modalIcon(Heroicon::OutlinedTrash)
                    ->modalHeading(__('ingredient_enrichment_admin.actions.delete_heading'))
                    ->modalDescription(__('ingredient_enrichment_admin.actions.delete_description'))
                    ->modalSubmitActionLabel(__('ingredient_enrichment_admin.actions.delete_submit'))
                    ->requiresConfirmation()
                    ->visible(fn (IngredientEnrichmentBatch $record): bool => $record->status->isTerminal())
                    ->action(function (Action $action, IngredientEnrichmentBatch $record, DeleteIngredientEnrichmentBatchAction $deleteBatch): void {
                        $actor = auth()->user();

                        abort_unless($actor instanceof User, 403);

                        try {
                            $cleanupComplete = $deleteBatch->handle($actor, $record);
                        } catch (ValidationException $exception) {
                            Notification::make()
                                ->title(__('ingredient_enrichment_admin.notifications.delete_failed'))
                                ->body($exception->errors()['batch'][0] ?? __('ingredient_enrichment_admin.notifications.delete_failed'))
                                ->danger()
                                ->send();

                            $action->halt();

                            return;
                        } catch (Throwable $exception) {
                            report($exception);

                            Notification::make()
                                ->title(__('ingredient_enrichment_admin.notifications.delete_failed'))
                                ->danger()
                                ->send();

                            $action->halt();

                            return;
                        }

                        Notification::make()
                            ->title(__($cleanupComplete
                                ? 'ingredient_enrichment_admin.notifications.deleted'
                                : 'ingredient_enrichment_admin.notifications.deleted_with_cleanup_warning'))
                            ->body($cleanupComplete ? null : __('ingredient_enrichment_admin.notifications.cleanup_warning'))
                            ->status($cleanupComplete ? 'success' : 'warning')
                            ->send();
                    }),
            ])->defaultSort('created_at', 'desc');
    }
}
