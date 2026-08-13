<?php

namespace App\Actions\IngredientEnrichment;

use App\Enums\IngredientEnrichmentItemStatus;
use App\Models\Ingredient;
use App\Models\IngredientEnrichmentBatchItem;
use App\Models\User;
use App\Services\IngredientEnrichment\IngredientEnrichmentBatchService;
use App\Services\IngredientEnrichment\IngredientEnrichmentPlanner;
use App\Services\IngredientEnrichment\IngredientEnrichmentResultValidator;
use App\Services\IngredientEnrichment\IngredientEnrichmentSnapshotBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ApproveIngredientEnrichmentItem
{
    public function __construct(
        private readonly IngredientEnrichmentSnapshotBuilder $snapshots,
        private readonly IngredientEnrichmentResultValidator $validator,
        private readonly IngredientEnrichmentPlanner $planner,
        private readonly IngredientEnrichmentBatchService $batches,
    ) {}

    /** @param list<string> $replaceFields */
    public function handle(User $actor, IngredientEnrichmentBatchItem $item, array $replaceFields = []): IngredientEnrichmentBatchItem
    {
        Gate::forUser($actor)->authorize('approve', $item->batch);

        return DB::transaction(function () use ($actor, $item, $replaceFields): IngredientEnrichmentBatchItem {
            $locked = IngredientEnrichmentBatchItem::query()->lockForUpdate()->findOrFail($item->id);
            if (! in_array($locked->status, [IngredientEnrichmentItemStatus::Ready, IngredientEnrichmentItemStatus::Warning], true)) {
                throw ValidationException::withMessages(['item' => __('ingredient_enrichment_admin.validation.not_approvable')]);
            }
            $ingredient = Ingredient::query()->withoutGlobalScopes()->lockForUpdate()->find($locked->ingredient_id);
            if (! $ingredient || $this->snapshots->fingerprint($ingredient) !== $locked->source_fingerprint) {
                $locked->update(['status' => IngredientEnrichmentItemStatus::Stale]);
                $this->batches->refresh($locked->ingredient_enrichment_batch_id);
                throw ValidationException::withMessages(['item' => __('ingredient_enrichment_admin.validation.stale')]);
            }
            $report = $this->validator->validateOrFail($locked->result, $ingredient);
            $normalized = $report['normalized'];
            $normalizedReplace = $this->planner->normalizeReplaceFields($replaceFields);
            $plan = $this->planner->plan($ingredient, $normalized, $normalizedReplace);
            $locked->update([
                'status' => IngredientEnrichmentItemStatus::Approved,
                'result' => $normalized,
                'validation_report' => $report,
                'plan' => $plan,
                'replacement_fields' => $normalizedReplace,
                'approved_by_user_id' => $actor->id,
                'approved_at' => now(),
            ]);
            $this->batches->refresh($locked->ingredient_enrichment_batch_id);

            return $locked->refresh();
        }, attempts: 5);
    }
}
