<?php

namespace App\Actions\IngredientEnrichment;

use App\Enums\IngredientEnrichmentItemStatus;
use App\Models\IngredientEnrichmentBatchItem;
use App\Models\User;
use App\Services\IngredientEnrichment\IngredientEnrichmentBatchService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class RejectIngredientEnrichmentItem
{
    public function __construct(
        private readonly IngredientEnrichmentBatchService $batches,
    ) {}

    public function handle(User $actor, IngredientEnrichmentBatchItem $item, ?string $reason = null): IngredientEnrichmentBatchItem
    {
        Gate::forUser($actor)->authorize('approve', $item->batch);

        $reason = is_string($reason) ? trim($reason) : '';
        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => __('ingredient_enrichment_admin.validation.rejection_reason_required'),
            ]);
        }

        return DB::transaction(function () use ($actor, $item, $reason): IngredientEnrichmentBatchItem {
            $locked = IngredientEnrichmentBatchItem::query()->lockForUpdate()->findOrFail($item->id);
            if (! in_array($locked->status, [IngredientEnrichmentItemStatus::Ready, IngredientEnrichmentItemStatus::Warning], true)) {
                throw ValidationException::withMessages([
                    'item' => __('ingredient_enrichment_admin.validation.not_rejectable'),
                ]);
            }

            $locked->update([
                'status' => IngredientEnrichmentItemStatus::Rejected,
                'rejected_by_user_id' => $actor->id,
                'rejected_at' => now(),
                'rejection_reason' => $reason,
                'approved_by_user_id' => null,
                'approved_at' => null,
            ]);
            $this->batches->refresh($locked->ingredient_enrichment_batch_id);

            return $locked->refresh();
        }, attempts: 5);
    }
}
