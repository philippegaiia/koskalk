<?php

namespace App\Filament\Resources\IngredientEnrichmentBatches\Pages;

use App\Actions\IngredientEnrichment\ApplyApprovedIngredientEnrichment;
use App\Actions\IngredientEnrichment\CancelIngredientEnrichmentBatch;
use App\Actions\IngredientEnrichment\RetryIngredientEnrichmentFailures;
use App\Filament\Resources\IngredientEnrichmentBatches\IngredientEnrichmentBatchResource;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewIngredientEnrichmentBatch extends ViewRecord
{
    protected static string $resource = IngredientEnrichmentBatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('applyApproved')
                ->label(__('ingredient_enrichment_admin.actions.apply'))
                ->icon('heroicon-o-check-circle')
                ->requiresConfirmation()
                ->action(function (ApplyApprovedIngredientEnrichment $applyApproved): void {
                    $totals = $applyApproved->handle(auth()->user(), $this->getRecord());
                    Notification::make()->success()->title(__('ingredient_enrichment_admin.notifications.applied', $totals))->send();
                    $this->refreshFormData([]);
                }),
            Action::make('retryFailures')
                ->label(__('ingredient_enrichment_admin.actions.retry'))
                ->icon('heroicon-o-arrow-path')
                ->action(function (RetryIngredientEnrichmentFailures $retryFailures): void {
                    $retryFailures->handle(auth()->user(), $this->getRecord());
                    Notification::make()->success()->title(__('ingredient_enrichment_admin.notifications.retried'))->send();
                }),
            Action::make('cancelPending')
                ->label(__('ingredient_enrichment_admin.actions.cancel'))
                ->color('danger')
                ->requiresConfirmation()
                ->action(function (CancelIngredientEnrichmentBatch $cancelBatch): void {
                    $cancelBatch->handle(auth()->user(), $this->getRecord());
                    Notification::make()->success()->title(__('ingredient_enrichment_admin.notifications.cancelled'))->send();
                }),
        ];
    }
}
