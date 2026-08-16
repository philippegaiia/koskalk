<?php

namespace App\Actions\IngredientEnrichment;

use App\Models\IngredientEnrichmentBatch;
use App\Models\User;
use App\Services\IngredientEnrichment\IngredientEnrichmentBatchDeletionService;
use Illuminate\Support\Facades\Gate;

class DeleteIngredientEnrichmentBatch
{
    public function __construct(
        private readonly IngredientEnrichmentBatchDeletionService $batches,
    ) {}

    public function handle(User $actor, IngredientEnrichmentBatch $batch): bool
    {
        Gate::forUser($actor)->authorize('delete', $batch);

        return $this->batches->delete($batch);
    }
}
