<?php

namespace App\Actions\IngredientEnrichment;

use App\Models\IngredientEnrichmentBatch;
use App\Models\User;
use App\Services\IngredientEnrichment\ApplyIngredientGuidanceRefresh;
use Illuminate\Support\Facades\Gate;

class ApplyApprovedIngredientGuidanceRefresh
{
    public function __construct(
        private readonly ApplyIngredientGuidanceRefresh $service,
    ) {}

    /** @return array{applied:int,unchanged:int,stale:int,failed:int} */
    public function handle(User $actor, IngredientEnrichmentBatch $batch): array
    {
        Gate::forUser($actor)->authorize('apply', $batch);

        return $this->service->handle($actor, $batch);
    }
}
