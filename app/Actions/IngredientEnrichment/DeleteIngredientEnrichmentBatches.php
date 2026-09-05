<?php

namespace App\Actions\IngredientEnrichment;

use App\Models\IngredientEnrichmentBatch;
use App\Models\User;
use App\Services\IngredientEnrichment\IngredientEnrichmentBatchDeletionService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class DeleteIngredientEnrichmentBatches
{
    public function __construct(
        private readonly IngredientEnrichmentBatchDeletionService $batches,
    ) {}

    /**
     * @param  Collection<int, IngredientEnrichmentBatch|int|string>  $selectedBatches
     */
    public function handle(User $actor, Collection $selectedBatches): bool
    {
        $batchIds = $selectedBatches
            ->map(fn (IngredientEnrichmentBatch|int|string $batch): int => (int) ($batch instanceof IngredientEnrichmentBatch ? $batch->getKey() : $batch))
            ->unique()
            ->sort()
            ->values();
        $batches = IngredientEnrichmentBatch::query()
            ->whereIn('id', $batchIds)
            ->orderBy('id')
            ->get();

        if ($batches->count() !== $batchIds->count()) {
            throw ValidationException::withMessages([
                'batch' => __('ingredient_enrichment_admin.validation.batch_not_found'),
            ]);
        }

        foreach ($batches as $batch) {
            Gate::forUser($actor)->authorize('delete', $batch);
        }

        return $this->batches->deleteMany($batchIds);
    }
}
