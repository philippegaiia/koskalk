<?php

namespace App\Actions\IngredientEnrichment;

use App\Models\IngredientEnrichmentBatchItem;
use App\Models\User;
use App\Services\IngredientEnrichment\IngredientGuidanceProposalReviewService;
use Illuminate\Support\Facades\Gate;

class EditIngredientGuidanceProposal
{
    public function __construct(
        private readonly IngredientGuidanceProposalReviewService $reviews,
    ) {}

    /** @param array<string, mixed> $proposal */
    public function handle(User $actor, IngredientEnrichmentBatchItem $item, array $proposal): IngredientEnrichmentBatchItem
    {
        Gate::forUser($actor)->authorize('approve', $item->batch);

        return $this->reviews->edit($actor, $item, $proposal);
    }
}
