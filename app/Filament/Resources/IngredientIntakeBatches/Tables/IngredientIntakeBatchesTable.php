<?php

namespace App\Filament\Resources\IngredientIntakeBatches\Tables;

use App\Actions\IngredientIntake\DeleteIngredientIntakeBatch;
use App\Enums\IngredientIntakeBatchStatus;
use App\Filament\Resources\IngredientEnrichmentBatches\IngredientEnrichmentBatchResource;
use App\Models\IngredientEnrichmentBatch;
use App\Models\IngredientIntakeBatch;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;
use Throwable;

class IngredientIntakeBatchesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('ingredient_intake_admin.table.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('ingredient_intake_admin.table.status'))
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state->label()),
                TextColumn::make('family_hint')
                    ->label(__('ingredient_intake_admin.table.family'))
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state?->label() ?? __('ingredient_intake_admin.table.no_family')),
                TextColumn::make('progress')
                    ->label(__('ingredient_intake_admin.table.progress'))
                    ->state(fn (IngredientIntakeBatch $record): string => ($record->ready_count + $record->approved_count + $record->promoted_count + $record->rejected_count).' / '.$record->total_count),
                TextColumn::make('creator.name')
                    ->label(__('ingredient_intake_admin.table.created_by')),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(collect(IngredientIntakeBatchStatus::cases())->mapWithKeys(
                    fn (IngredientIntakeBatchStatus $status): array => [$status->value => $status->label()],
                )->all()),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('reviewResearch')
                    ->label(__('ingredient_intake_admin.actions.review_research'))
                    ->icon('heroicon-o-eye')
                    ->url(fn (IngredientIntakeBatch $record): ?string => $record->enrichmentBatch instanceof IngredientEnrichmentBatch
                        ? IngredientEnrichmentBatchResource::getUrl('view', ['record' => $record->enrichmentBatch])
                    : null)
                    ->visible(fn (IngredientIntakeBatch $record): bool => $record->enrichmentBatch !== null),
                Action::make('delete')
                    ->label(__('ingredient_intake_admin.actions.delete'))
                    ->color('danger')
                    ->icon('heroicon-o-trash')
                    ->requiresConfirmation()
                    ->modalHeading(__('ingredient_intake_admin.actions.delete_heading'))
                    ->modalDescription(__('ingredient_intake_admin.actions.delete_description'))
                    ->modalSubmitActionLabel(__('ingredient_intake_admin.actions.delete_submit'))
                    ->visible(fn (IngredientIntakeBatch $record): bool => $record->status !== IngredientIntakeBatchStatus::Researching)
                    ->action(function (Action $action, IngredientIntakeBatch $record, DeleteIngredientIntakeBatch $deleteBatch): void {
                        $actor = auth()->user();

                        abort_unless($actor instanceof User, 403);

                        try {
                            $cleanupComplete = $deleteBatch->handle($actor, $record);
                        } catch (ValidationException $exception) {
                            Notification::make()
                                ->title(__('ingredient_intake_admin.notifications.delete_failed'))
                                ->body($exception->errors()['batch'][0] ?? __('ingredient_intake_admin.notifications.delete_failed'))
                                ->danger()
                                ->send();

                            $action->halt();

                            return;
                        } catch (Throwable $exception) {
                            report($exception);

                            Notification::make()
                                ->title(__('ingredient_intake_admin.notifications.delete_failed'))
                                ->danger()
                                ->send();

                            $action->halt();

                            return;
                        }

                        Notification::make()
                            ->title(__($cleanupComplete
                                ? 'ingredient_intake_admin.notifications.deleted'
                                : 'ingredient_intake_admin.notifications.deleted_with_cleanup_warning'))
                            ->body($cleanupComplete ? null : __('ingredient_intake_admin.notifications.cleanup_warning'))
                            ->status($cleanupComplete ? 'success' : 'warning')
                            ->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
