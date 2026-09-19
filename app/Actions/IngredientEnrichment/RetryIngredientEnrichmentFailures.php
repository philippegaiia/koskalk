<?php

namespace App\Actions\IngredientEnrichment;

use App\Enums\IngredientEnrichmentBatchMode;
use App\Enums\IngredientEnrichmentBatchStatus;
use App\Enums\IngredientEnrichmentItemStatus;
use App\Enums\IngredientEnrichmentResearchStage;
use App\Jobs\GenerateIngredientGuidanceRefresh;
use App\Jobs\ResearchIngredientEnrichment;
use App\Models\Ingredient;
use App\Models\IngredientEnrichmentBatch;
use App\Models\IngredientEnrichmentBatchItem;
use App\Models\IngredientIntakeItem;
use App\Models\User;
use App\Services\IngredientEnrichment\IngredientEnrichmentInputBuilder;
use App\Services\IngredientEnrichment\IngredientEnrichmentSnapshotBuilder;
use App\Services\IngredientEnrichment\IngredientEnrichmentStageStore;
use App\Services\IngredientEnrichment\IngredientEnrichmentSubjectBuilder;
use App\Services\IngredientEnrichment\IngredientGuidanceContextBuilder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class RetryIngredientEnrichmentFailures
{
    public function __construct(
        private readonly IngredientEnrichmentSnapshotBuilder $snapshots,
        private readonly IngredientEnrichmentStageStore $stages,
        private readonly IngredientEnrichmentInputBuilder $inputBuilder,
        private readonly IngredientEnrichmentSubjectBuilder $subjects,
        private readonly IngredientGuidanceContextBuilder $contexts,
    ) {}

    public function handle(
        User $actor,
        IngredientEnrichmentBatch $batch,
        bool $allowGapResearch = false,
    ): IngredientEnrichmentBatch {
        Gate::forUser($actor)->authorize('retry', $batch);
        /** @var array{ids:list<int>, mode:IngredientEnrichmentBatchMode|null} $retry */
        $retry = DB::transaction(function () use ($batch): array {
            $locked = IngredientEnrichmentBatch::query()->lockForUpdate()->findOrFail($batch->id);
            $ids = [];
            foreach ($locked->items()->whereIn('status', [
                IngredientEnrichmentItemStatus::Failed->value,
                IngredientEnrichmentItemStatus::Warning->value,
                IngredientEnrichmentItemStatus::Stale->value,
            ])->lockForUpdate()->get() as $item) {
                $retryFrom = $item->retryableFromStage(
                    $locked->mode instanceof IngredientEnrichmentBatchMode ? $locked->mode : null,
                );
                if (! $retryFrom instanceof IngredientEnrichmentResearchStage) {
                    continue;
                }

                // A stale item is re-researched from the first stage, so it needs a snapshot
                // and fingerprint taken from the subject as it is now.
                if ($item->status === IngredientEnrichmentItemStatus::Stale) {
                    $record = $this->refreshSnapshot(
                        $item,
                        $locked->mode instanceof IngredientEnrichmentBatchMode ? $locked->mode : null,
                    );
                    if ($record === null) {
                        continue;
                    }

                    $this->stages->invalidateFrom($item->id, $retryFrom);
                    $item->update([
                        'status' => IngredientEnrichmentItemStatus::Pending,
                        'snapshot' => $record,
                        'source_fingerprint' => $record['source_fingerprint'],
                        'failure_code' => null,
                        'failure_message' => null,
                    ]);
                    $ids[] = $item->id;

                    continue;
                }

                $ingredient = $item->ingredient;
                if (! $ingredient instanceof Ingredient) {
                    continue;
                }

                if ($this->snapshots->fingerprint($ingredient) !== $item->source_fingerprint) {
                    $item->update(['status' => IngredientEnrichmentItemStatus::Stale]);

                    continue;
                }

                $this->stages->invalidateFrom($item->id, $retryFrom);
                $item->update([
                    'status' => IngredientEnrichmentItemStatus::Pending,
                    'failure_code' => null,
                    'failure_message' => null,
                ]);
                $ids[] = $item->id;
            }

            return [
                'ids' => $ids,
                'mode' => $locked->mode instanceof IngredientEnrichmentBatchMode ? $locked->mode : null,
            ];
        }, attempts: 5);
        $ids = $retry['ids'];
        $mode = $retry['mode'];

        if ($ids !== []) {
            $jobs = collect($ids)->map(function (int $id) use ($mode, $allowGapResearch): object {
                if ($mode instanceof IngredientEnrichmentBatchMode && $mode->isGuidance()) {
                    return new GenerateIngredientGuidanceRefresh($id);
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

    /**
     * Re-take the snapshot a stale item was researched against.
     *
     * The shape depends on the subject: intake rows carry their own subject identity,
     * guidance rows carry guidance evidence, and platform rows carry the plain
     * catalogue snapshot. Rebuilding with the wrong builder drops part of the
     * research input, so each subject keeps the builder it was started with.
     *
     * @return array<string, mixed>|null
     */
    private function refreshSnapshot(
        IngredientEnrichmentBatchItem $item,
        ?IngredientEnrichmentBatchMode $mode,
    ): ?array {
        if ($item->ingredient_intake_item_id !== null) {
            $intakeItem = IngredientIntakeItem::query()
                ->with(['batch', 'existingIngredient'])
                ->find($item->ingredient_intake_item_id);

            return $intakeItem instanceof IngredientIntakeItem
                ? $this->inputBuilder->buildForSubject($this->subjects->forIntake($intakeItem))
                : null;
        }

        $ingredient = $item->ingredient;
        if (! $ingredient instanceof Ingredient) {
            return null;
        }

        return $mode?->isGuidance() === true
            ? $this->contexts->build($ingredient)
            : $this->inputBuilder->build($ingredient);
    }
}
