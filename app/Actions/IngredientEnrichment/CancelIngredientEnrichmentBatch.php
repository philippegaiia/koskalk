<?php

namespace App\Actions\IngredientEnrichment;

use App\Enums\IngredientEnrichmentBatchStatus;
use App\Enums\IngredientEnrichmentItemStatus;
use App\Models\IngredientEnrichmentBatch;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class CancelIngredientEnrichmentBatch
{
    public function handle(User $actor, IngredientEnrichmentBatch $batch): IngredientEnrichmentBatch
    {
        Gate::forUser($actor)->authorize('cancel', $batch);
        if ($batch->laravel_batch_id) {
            Bus::findBatch($batch->laravel_batch_id)?->cancel();
        }

        return DB::transaction(function () use ($batch): IngredientEnrichmentBatch {
            $locked = IngredientEnrichmentBatch::query()->lockForUpdate()->findOrFail($batch->id);
            $locked->items()->where('status', IngredientEnrichmentItemStatus::Pending->value)
                ->update(['status' => IngredientEnrichmentItemStatus::Cancelled]);
            $locked->update(['status' => IngredientEnrichmentBatchStatus::Cancelled, 'cancelled_at' => now(), 'completed_at' => now()]);

            return $locked->refresh();
        }, attempts: 5);
    }
}
