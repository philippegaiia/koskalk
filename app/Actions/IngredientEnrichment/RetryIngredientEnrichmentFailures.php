<?php

namespace App\Actions\IngredientEnrichment;

use App\Enums\IngredientEnrichmentBatchStatus;
use App\Enums\IngredientEnrichmentItemStatus;
use App\Jobs\ResearchIngredientEnrichment;
use App\Models\IngredientEnrichmentBatch;
use App\Models\User;
use App\Services\IngredientEnrichment\IngredientEnrichmentSnapshotBuilder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class RetryIngredientEnrichmentFailures
{
    public function __construct(private readonly IngredientEnrichmentSnapshotBuilder $snapshots) {}

    public function handle(User $actor, IngredientEnrichmentBatch $batch): IngredientEnrichmentBatch
    {
        Gate::forUser($actor)->authorize('retry', $batch);
        $ids = DB::transaction(function () use ($batch): array {
            $locked = IngredientEnrichmentBatch::query()->lockForUpdate()->findOrFail($batch->id);
            $ids = [];
            foreach ($locked->items()->where('status', IngredientEnrichmentItemStatus::Failed->value)->lockForUpdate()->get() as $item) {
                $ingredient = $item->ingredient;
                if (! $ingredient || $this->snapshots->fingerprint($ingredient) !== $item->source_fingerprint) {
                    $item->update(['status' => IngredientEnrichmentItemStatus::Stale]);

                    continue;
                }
                $item->update(['status' => IngredientEnrichmentItemStatus::Pending, 'failure_code' => null, 'failure_message' => null]);
                $ids[] = $item->id;
            }

            return $ids;
        }, attempts: 5);

        if ($ids !== []) {
            $laravelBatch = Bus::batch(collect($ids)->map(fn (int $id): ResearchIngredientEnrichment => new ResearchIngredientEnrichment($id))->all())
                ->name("ingredient-enrichment-retry:{$batch->public_id}")->allowFailures()
                ->onQueue((string) config('ingredient-enrichment.direct_ai.queue'))->dispatch();
            $batch->update(['laravel_batch_id' => $laravelBatch->id, 'status' => IngredientEnrichmentBatchStatus::Processing, 'completed_at' => null]);
        }

        return $batch->refresh();
    }
}
