<?php

namespace App\Actions\IngredientEnrichment;

use App\Enums\IngredientEnrichmentBatchMode;
use App\Enums\IngredientEnrichmentBatchStatus;
use App\Enums\IngredientEnrichmentItemStatus;
use App\Enums\IngredientEnrichmentResearchStage;
use App\Jobs\GenerateIngredientGuidanceRefresh;
use App\Jobs\ResearchIngredientEnrichment;
use App\Models\IngredientEnrichmentBatch;
use App\Models\User;
use App\Services\IngredientEnrichment\IngredientEnrichmentSnapshotBuilder;
use App\Services\IngredientEnrichment\IngredientEnrichmentStageStore;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class RetryIngredientEnrichmentFailures
{
    public function __construct(
        private readonly IngredientEnrichmentSnapshotBuilder $snapshots,
        private readonly IngredientEnrichmentStageStore $stages,
    ) {}

    public function handle(
        User $actor,
        IngredientEnrichmentBatch $batch,
        bool $allowGapResearch = false,
    ): IngredientEnrichmentBatch {
        Gate::forUser($actor)->authorize('retry', $batch);
        $ids = DB::transaction(function () use ($batch): array {
            $locked = IngredientEnrichmentBatch::query()->lockForUpdate()->findOrFail($batch->id);
            $ids = [];
            foreach ($locked->items()->whereIn('status', [
                IngredientEnrichmentItemStatus::Failed->value,
                IngredientEnrichmentItemStatus::Warning->value,
            ])->lockForUpdate()->get() as $item) {
                $retryFrom = $item->retryableFromStage();
                if (! $retryFrom instanceof IngredientEnrichmentResearchStage) {
                    continue;
                }
                $ingredient = $item->ingredient;
                if (! $ingredient || $this->snapshots->fingerprint($ingredient) !== $item->source_fingerprint) {
                    $item->update(['status' => IngredientEnrichmentItemStatus::Stale]);

                    continue;
                }
                $this->stages->invalidateFrom($item->id, $retryFrom);
                $item->update(['status' => IngredientEnrichmentItemStatus::Pending, 'failure_code' => null, 'failure_message' => null]);
                $ids[] = $item->id;
            }

            return $ids;
        }, attempts: 5);

        if ($ids !== []) {
            $mode = $batch->mode;
            $jobs = collect($ids)->map(function (int $id) use ($mode, $allowGapResearch): object {
                if ($mode instanceof IngredientEnrichmentBatchMode && $mode->isGuidance()) {
                    return new GenerateIngredientGuidanceRefresh($id, $mode->isLocalizationOnly());
                }

                return new ResearchIngredientEnrichment($id, $allowGapResearch);
            })->all();
            $laravelBatch = Bus::batch($jobs)
                ->name("ingredient-enrichment-retry:{$batch->public_id}")->allowFailures()
                ->onQueue((string) config('ingredient-enrichment.direct_ai.queue'))->dispatch();
            $batch->update(['laravel_batch_id' => $laravelBatch->id, 'status' => IngredientEnrichmentBatchStatus::Processing, 'completed_at' => null]);
        }

        return $batch->refresh();
    }
}
