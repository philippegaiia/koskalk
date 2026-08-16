<?php

namespace App\Actions\IngredientIntake;

use App\Models\IngredientEnrichmentBatch;
use App\Models\IngredientIntakeBatch;
use App\Models\User;
use App\Services\IngredientEnrichment\IngredientEnrichmentBatchService;
use Illuminate\Support\Facades\Gate;

final class StartIngredientIntakeResearch
{
    public function __construct(
        private readonly IngredientEnrichmentBatchService $service,
    ) {}

    public function handle(User $actor, IngredientIntakeBatch $intakeBatch): IngredientEnrichmentBatch
    {
        Gate::forUser($actor)->authorize('update', $intakeBatch);

        return $this->service->startIntake($actor, $intakeBatch);
    }
}
