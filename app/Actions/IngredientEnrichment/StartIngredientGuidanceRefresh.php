<?php

namespace App\Actions\IngredientEnrichment;

use App\Models\Ingredient;
use App\Models\IngredientEnrichmentBatch;
use App\Models\User;
use App\Services\IngredientEnrichment\IngredientEnrichmentBatchService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

class StartIngredientGuidanceRefresh
{
    public function __construct(
        private readonly IngredientEnrichmentBatchService $service,
    ) {}

    /** @param Collection<int, Ingredient> $ingredients */
    public function handle(
        User $actor,
        Collection $ingredients,
        bool $localizationOnly = false,
        bool $freshResearch = false,
    ): IngredientEnrichmentBatch {
        Gate::forUser($actor)->authorize('create', IngredientEnrichmentBatch::class);

        return $this->service->startGuidanceRefresh($actor, $ingredients, $localizationOnly, $freshResearch);
    }
}
