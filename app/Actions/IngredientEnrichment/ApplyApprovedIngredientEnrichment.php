<?php

namespace App\Actions\IngredientEnrichment;

use App\Actions\IngredientIntake\PromoteIngredientIntakeItem;
use App\Enums\IngredientEnrichmentBatchMode;
use App\Enums\IngredientEnrichmentBatchStatus;
use App\Enums\IngredientEnrichmentItemStatus;
use App\Models\IngredientEnrichmentBatch;
use App\Models\IngredientEnrichmentBatchItem;
use App\Models\User;
use App\Services\IngredientEnrichment\ApplyPlatformIngredientEnrichment;
use App\Services\IngredientEnrichment\IngredientEnrichmentBatchService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

class ApplyApprovedIngredientEnrichment
{
    public function __construct(
        private readonly ApplyPlatformIngredientEnrichment $applier,
        private readonly IngredientEnrichmentBatchService $batches,
        private readonly PromoteIngredientIntakeItem $promoter,
        private readonly ApplyApprovedIngredientGuidanceRefresh $guidanceRefresh,
    ) {}

    /** @return array{applied:int,unchanged:int,stale:int,failed:int} */
    public function handle(User $actor, IngredientEnrichmentBatch $batch): array
    {
        Gate::forUser($actor)->authorize('apply', $batch);
        if ($batch->mode instanceof IngredientEnrichmentBatchMode && $batch->mode->isGuidance()) {
            return $this->guidanceRefresh->handle($actor, $batch);
        }

        $totals = ['applied' => 0, 'unchanged' => 0, 'stale' => 0, 'failed' => 0];
        $approvedItemIds = $batch->items()
            ->where('status', IngredientEnrichmentItemStatus::Approved->value)
            ->pluck('id');

        foreach ($approvedItemIds as $itemId) {
            $target = IngredientEnrichmentBatchItem::query()->find($itemId);

            if ($target?->ingredient_intake_item_id !== null) {
                try {
                    $this->promoter->handle($actor, $target);
                    $totals['applied']++;
                } catch (ValidationException $exception) {
                    $this->recordPromotionFailure($itemId, $exception);
                    $totals['failed']++;
                } catch (Throwable $exception) {
                    report($exception);
                    $this->recordPromotionFailure($itemId);
                    $totals['failed']++;
                }

                continue;
            }

            try {
                $item = DB::transaction(function () use ($itemId): IngredientEnrichmentBatchItem {
                    $item = IngredientEnrichmentBatchItem::query()->lockForUpdate()->findOrFail($itemId);
                    $item->update(['status' => IngredientEnrichmentItemStatus::Applying]);

                    return $item;
                }, attempts: 5);
                $result = $this->applier->apply($item->plan, $item->result, $item->replacement_fields ?? []);
                $status = $result['status'] === 'unchanged' ? 'unchanged' : 'applied';
                DB::transaction(function () use ($item, $actor, $status): void {
                    $locked = IngredientEnrichmentBatchItem::query()->lockForUpdate()->findOrFail($item->id);
                    $locked->update([
                        'status' => $status === 'applied' ? IngredientEnrichmentItemStatus::Applied : IngredientEnrichmentItemStatus::Unchanged,
                        'applied_by_user_id' => $actor->id,
                        'applied_at' => now(),
                    ]);
                }, attempts: 5);
                $totals[$status]++;
            } catch (ValidationException) {
                IngredientEnrichmentBatchItem::query()->whereKey($itemId)->update(['status' => IngredientEnrichmentItemStatus::Stale]);
                $totals['stale']++;
            } catch (Throwable $exception) {
                report($exception);
                IngredientEnrichmentBatchItem::query()->whereKey($itemId)->update([
                    'status' => IngredientEnrichmentItemStatus::Failed,
                    'failure_message' => __('ingredient_enrichment_admin.validation.apply_failed'),
                ]);
                $totals['failed']++;
            }
        }

        $this->batches->refresh($batch->id);
        if ($approvedItemIds->isNotEmpty()
            && $totals['failed'] === 0
            && $totals['stale'] === 0
            && $batch->items()->whereNotIn('status', [
                IngredientEnrichmentItemStatus::Applied->value,
                IngredientEnrichmentItemStatus::Unchanged->value,
                IngredientEnrichmentItemStatus::Rejected->value,
                IngredientEnrichmentItemStatus::Cancelled->value,
            ])->doesntExist()) {
            $batch->refresh()->update(['status' => IngredientEnrichmentBatchStatus::Applied, 'completed_at' => now()]);
        }

        return $totals;
    }

    private function recordPromotionFailure(int $itemId, ?ValidationException $exception = null): void
    {
        $message = $exception instanceof ValidationException
            ? collect($exception->errors())->flatten()->first()
            : null;

        IngredientEnrichmentBatchItem::query()
            ->whereKey($itemId)
            ->update([
                'failure_message' => is_string($message) && $message !== ''
                    ? $message
                    : __('ingredient_enrichment_admin.validation.promotion_failed'),
            ]);
    }
}
