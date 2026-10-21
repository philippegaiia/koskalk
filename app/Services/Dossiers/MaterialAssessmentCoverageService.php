<?php

namespace App\Services\Dossiers;

use App\Enums\AssessmentReviewState;
use App\Enums\WorkspaceModule;
use App\Models\Ingredient;
use App\Models\MaterialAssessmentCoverage;
use App\Models\Substance;
use App\Models\ToxicologyProfile;
use App\Models\User;
use App\Services\WorkspaceAuthorization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MaterialAssessmentCoverageService
{
    public function __construct(private readonly WorkspaceAuthorization $authorization) {}

    public function coverageFor(Ingredient|Substance $material): ?MaterialAssessmentCoverage
    {
        return MaterialAssessmentCoverage::query()
            ->where('coverable_type', $material::class)
            ->where('coverable_id', $material->getKey())
            ->first();
    }

    public function stateFor(Ingredient|Substance $material): AssessmentReviewState
    {
        return $this->coverageFor($material)?->review_state ?? AssessmentReviewState::NotReviewed;
    }

    /**
     * @param  array{review_state: string, insufficient_reason?: string|null, covers_whole_material?: bool,
     *               covers_constituents?: bool, supporting_substance_ids?: list<int>, note?: string|null}  $review
     */
    public function review(User $actor, Ingredient|Substance $material, array $review): MaterialAssessmentCoverage
    {
        $this->assertCanReview($actor, $material);

        $state = AssessmentReviewState::from($review['review_state']);
        $coversWhole = (bool) ($review['covers_whole_material'] ?? false);
        $coversConstituents = (bool) ($review['covers_constituents'] ?? false);
        $substanceIds = collect($review['supporting_substance_ids'] ?? [])->map(fn (mixed $id): int => (int) $id)->unique()->values();

        $this->assertConsistent($material, $state, $review['insufficient_reason'] ?? null, $coversWhole, $coversConstituents, $substanceIds->all());

        return DB::transaction(function () use ($actor, $material, $review, $state, $coversWhole, $coversConstituents, $substanceIds): MaterialAssessmentCoverage {
            MaterialAssessmentCoverage::query()
                ->where('coverable_type', $material::class)
                ->where('coverable_id', $material->getKey())
                ->lockForUpdate()
                ->first();

            $coverage = MaterialAssessmentCoverage::query()->updateOrCreate(
                ['coverable_type' => $material::class, 'coverable_id' => $material->getKey()],
                [
                    'workspace_id' => $material instanceof Ingredient ? $material->workspace_id : null,
                    'review_state' => $state,
                    'insufficient_reason' => $state === AssessmentReviewState::Insufficient ? trim((string) $review['insufficient_reason']) : null,
                    'covers_whole_material' => $coversWhole,
                    'covers_constituents' => $coversConstituents,
                    'note' => $review['note'] ?? null,
                    'reviewed_by_user_id' => $actor->id,
                    'reviewed_at' => now(),
                ],
            );

            $coverage->supportingSubstances()->sync($substanceIds->all());

            return $coverage->load('supportingSubstances');
        }, attempts: 5);
    }

    private function assertCanReview(User $actor, Ingredient|Substance $material): void
    {
        $workspaceId = $material instanceof Ingredient ? $material->workspace_id : null;

        $allowed = $workspaceId === null
            ? (bool) $actor->is_admin
            : $this->authorization->canEditModule($actor, $workspaceId, WorkspaceModule::ProductDossiers);

        if (! $allowed) {
            throw new AuthorizationException;
        }
    }

    /**
     * @param  list<int>  $substanceIds
     */
    private function assertConsistent(
        Ingredient|Substance $material,
        AssessmentReviewState $state,
        ?string $insufficientReason,
        bool $coversWhole,
        bool $coversConstituents,
        array $substanceIds,
    ): void {
        $errors = [];

        if ($state === AssessmentReviewState::Insufficient && blank($insufficientReason)) {
            $errors['insufficient_reason'] = 'Name the uncertainty that prevents a conclusion.';
        }

        if ($state === AssessmentReviewState::Sufficient && ! $coversWhole && ! $coversConstituents) {
            $errors['covers_whole_material'] = 'Sufficient coverage needs the whole material, its constituents, or both.';
        }

        if ($coversWhole) {
            $profiled = ToxicologyProfile::query()->current()->whereIn('substance_id', $substanceIds)->distinct()->count('substance_id');

            if ($substanceIds === [] || $profiled !== count($substanceIds)) {
                $errors['supporting_substance_ids'] = 'Whole-material coverage needs profile references, each with a current toxicological profile.';
            }
        }

        if ($coversConstituents && ! $this->tracksConstituents($material)) {
            $errors['covers_constituents'] = 'No constituents are tracked for this material.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function tracksConstituents(Ingredient|Substance $material): bool
    {
        return $material instanceof Ingredient
            && ($material->substanceEntries()->exists() || $material->allergenEntries()->exists() || $material->components()->exists());
    }
}
