<?php

namespace App\Actions\IngredientIntake;

use App\Enums\IngredientIntakeItemStatus;
use App\Models\IngredientEnrichmentBatchItem;
use App\Models\IngredientIntakeItem;
use App\Models\User;
use App\Services\IngredientEnrichment\IngredientEnrichmentBatchService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class RemoveIngredientIntakeRow
{
    public function __construct(
        private readonly IngredientEnrichmentBatchService $batches,
    ) {}

    public function handle(User $actor, IngredientIntakeItem $item): void
    {
        Gate::forUser($actor)->authorize('update', $item->batch);

        DB::transaction(function () use ($item): void {
            $locked = IngredientIntakeItem::query()->lockForUpdate()->findOrFail($item->id);
            if (! in_array($locked->status, [IngredientIntakeItemStatus::Draft, IngredientIntakeItemStatus::NeedsResolution], true)) {
                throw ValidationException::withMessages([
                    'item' => __('ingredient_intake_admin.validation.row_not_removable'),
                ]);
            }

            IngredientEnrichmentBatchItem::query()
                ->where('ingredient_intake_item_id', $locked->id)
                ->lockForUpdate()
                ->get()
                ->each(function (IngredientEnrichmentBatchItem $enrichmentItem): void {
                    $enrichmentItem->delete();
                });

            $batchId = $locked->ingredient_intake_batch_id;
            $locked->delete();
            $this->batches->refreshIntake($batchId);
        }, attempts: 5);
    }
}
