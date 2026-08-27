<?php

namespace App\Actions\IngredientEnrichment;

use App\Enums\IngredientEnrichmentBatchMode;
use App\Enums\IngredientEnrichmentItemStatus;
use App\Models\Ingredient;
use App\Models\IngredientEnrichmentBatchItem;
use App\Models\User;
use App\Services\IngredientEnrichment\IngredientEnrichmentBatchService;
use App\Services\IngredientEnrichment\IngredientEnrichmentSnapshotBuilder;
use App\Services\IngredientEnrichment\IngredientGuidanceRefreshResultValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ApproveIngredientGuidanceProposal
{
    public function __construct(
        private readonly IngredientEnrichmentSnapshotBuilder $snapshots,
        private readonly IngredientGuidanceRefreshResultValidator $validator,
        private readonly IngredientEnrichmentBatchService $batches,
    ) {}

    public function handle(User $actor, IngredientEnrichmentBatchItem $item): IngredientEnrichmentBatchItem
    {
        Gate::forUser($actor)->authorize('approve', $item->batch);
        $outcome = DB::transaction(function () use ($actor, $item): array {
            $locked = IngredientEnrichmentBatchItem::query()->lockForUpdate()->findOrFail($item->id);
            if (! in_array($locked->status, [IngredientEnrichmentItemStatus::Ready, IngredientEnrichmentItemStatus::Warning], true)) {
                throw ValidationException::withMessages(['item' => __('ingredient_enrichment_admin.validation.not_approvable')]);
            }
            $batch = $locked->batch()->firstOrFail();
            $mode = $batch->mode;
            $ingredient = Ingredient::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($locked->ingredient_id);
            if (! $mode instanceof IngredientEnrichmentBatchMode || ! $mode->isGuidance()) {
                throw ValidationException::withMessages(['batch' => 'This is not a guidance refresh batch.']);
            }
            if ($this->snapshots->fingerprint($ingredient) !== $locked->source_fingerprint) {
                $locked->update(['status' => IngredientEnrichmentItemStatus::Stale]);
                $this->batches->refresh($locked->ingredient_enrichment_batch_id);

                return ['item' => $locked->refresh(), 'stale' => true];
            }
            $result = is_array($locked->result) ? $locked->result : [];
            $report = $this->validator->validateOrFail(
                $result,
                $ingredient,
                $mode,
                collect($result['translations'] ?? [])->pluck('locale')->filter()->values()->all(),
            );
            $locked->update([
                'status' => IngredientEnrichmentItemStatus::Approved,
                'result' => $report['normalized'],
                'validation_report' => $report,
                'approved_by_user_id' => $actor->id,
                'approved_at' => now(),
                'rejected_by_user_id' => null,
                'rejected_at' => null,
                'rejection_reason' => null,
            ]);
            $this->batches->refresh($locked->ingredient_enrichment_batch_id);

            return ['item' => $locked->refresh(), 'stale' => false];
        }, attempts: 5);

        if ($outcome['stale']) {
            throw ValidationException::withMessages(['item' => __('ingredient_enrichment_admin.validation.stale')]);
        }

        return $outcome['item'];
    }
}
