<?php

namespace App\Filament\Resources\IngredientIntakeBatches\Pages;

use App\Actions\IngredientIntake\StartIngredientIntakeResearch;
use App\Enums\IngredientIntakeBatchStatus;
use App\Filament\Resources\IngredientEnrichmentBatches\IngredientEnrichmentBatchResource;
use App\Filament\Resources\IngredientIntakeBatches\IngredientIntakeBatchResource;
use App\Models\IngredientEnrichmentBatch;
use App\Models\IngredientIntakeBatch;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewIngredientIntakeBatch extends ViewRecord
{
    protected static string $resource = IngredientIntakeBatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('startResearch')
                ->label(__('ingredient_intake_admin.actions.start_research'))
                ->icon('heroicon-o-sparkles')
                ->requiresConfirmation()
                ->modalDescription(__('ingredient_intake_admin.actions.start_research_description'))
                ->visible(fn (IngredientIntakeBatch $record): bool => in_array($record->status, [
                    IngredientIntakeBatchStatus::Draft,
                    IngredientIntakeBatchStatus::ReadyForReview,
                ], true))
                ->action(function (StartIngredientIntakeResearch $startResearch): void {
                    $enrichment = $startResearch->handle(auth()->user(), $this->getRecord());
                    Notification::make()
                        ->success()
                        ->title(__('ingredient_intake_admin.notifications.research_started', [
                            'count' => $enrichment->total_count,
                        ]))
                        ->send();
                    $this->refreshFormData([]);
                }),
            Action::make('reviewResearch')
                ->label(__('ingredient_intake_admin.actions.review_research'))
                ->icon('heroicon-o-eye')
                ->url(fn (IngredientIntakeBatch $record): ?string => $record->enrichmentBatch instanceof IngredientEnrichmentBatch
                    ? IngredientEnrichmentBatchResource::getUrl('view', ['record' => $record->enrichmentBatch])
                    : null)
                ->visible(fn (IngredientIntakeBatch $record): bool => $record->enrichmentBatch !== null),
        ];
    }
}
