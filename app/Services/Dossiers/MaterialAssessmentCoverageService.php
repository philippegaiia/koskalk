<?php

namespace App\Services\Dossiers;

use App\Enums\AssessmentReviewState;
use App\Enums\WorkspaceModule;
use App\Models\Ingredient;
use App\Models\MaterialAssessmentCoverage;
use App\Models\Substance;
use App\Models\ToxicologyProfile;
use App\Models\User;
use App\Models\Workspace;
use App\Services\WorkspaceAuthorization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
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
     * Records a review. Each supporting reference stores the toxicological profile version current at review time.
     * Sufficient coverage always rests on at least one profiled reference; constituent coverage additionally needs
     * every tracked constituent among those references. (Supplier assessments as evidence arrive with phase 2.)
     *
     * @param  array{review_state: string, insufficient_reason?: string|null, covers_whole_material?: bool,
     *               covers_constituents?: bool, supporting_substance_ids?: list<int>, note?: string|null}  $review
     */
    public function review(User $actor, Ingredient|Substance $material, array $review): MaterialAssessmentCoverage
    {
        $state = AssessmentReviewState::from($review['review_state']);
        $coversWhole = (bool) ($review['covers_whole_material'] ?? false);
        $coversConstituents = (bool) ($review['covers_constituents'] ?? false);
        $substanceIds = collect($review['supporting_substance_ids'] ?? [])->map(fn (mixed $id): int => (int) $id)->unique()->values();

        return DB::transaction(function () use ($actor, $material, $review, $state, $coversWhole, $coversConstituents, $substanceIds): MaterialAssessmentCoverage {
            $this->lockOwningWorkspace($material);
            $this->assertCanReview($actor, $material);

            $currentProfiles = ToxicologyProfile::query()
                ->current()
                ->whereIn('substance_id', $substanceIds->all())
                ->lockForUpdate()
                ->get()
                ->keyBy('substance_id');

            $this->assertConsistent($material, $state, $review['insufficient_reason'] ?? null, $coversWhole, $coversConstituents, $substanceIds, $currentProfiles);

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

            $coverage->supportingSubstances()->sync(
                $currentProfiles->mapWithKeys(fn (ToxicologyProfile $profile): array => [
                    $profile->substance_id => ['toxicology_profile_id' => $profile->id],
                ])->all(),
            );

            return $coverage->load('supportingSubstances');
        }, attempts: 5);
    }

    private function lockOwningWorkspace(Ingredient|Substance $material): void
    {
        if ($material instanceof Ingredient && $material->workspace_id !== null) {
            Workspace::withoutGlobalScopes()->whereKey($material->workspace_id)->lockForUpdate()->first();
        }
    }

    private function assertCanReview(User $actor, Ingredient|Substance $material): void
    {
        $workspaceId = $material instanceof Ingredient ? $material->workspace_id : null;
        $freshActor = $actor->fresh();

        $allowed = $freshActor instanceof User && ($workspaceId === null
            ? (bool) $freshActor->is_admin
            : $this->authorization->canEditModule($freshActor, $workspaceId, WorkspaceModule::ProductDossiers));

        if (! $allowed) {
            throw new AuthorizationException;
        }
    }

    /**
     * @param  Collection<int, int>  $substanceIds
     * @param  Collection<int, ToxicologyProfile>  $currentProfiles  Keyed by substance id.
     */
    private function assertConsistent(
        Ingredient|Substance $material,
        AssessmentReviewState $state,
        ?string $insufficientReason,
        bool $coversWhole,
        bool $coversConstituents,
        Collection $substanceIds,
        Collection $currentProfiles,
    ): void {
        $errors = [];

        if ($state === AssessmentReviewState::Insufficient && blank($insufficientReason)) {
            $errors['insufficient_reason'] = 'Name the uncertainty that prevents a conclusion.';
        }

        if ($state === AssessmentReviewState::Sufficient && ! $coversWhole && ! $coversConstituents) {
            $errors['covers_whole_material'] = 'Sufficient coverage needs the whole material, its constituents, or both.';
        }

        $unprofiled = $substanceIds->diff($currentProfiles->keys());

        if ($unprofiled->isNotEmpty()) {
            $errors['supporting_substance_ids'] = 'Every profile reference needs a current toxicological profile.';
        } elseif (($coversWhole || $state === AssessmentReviewState::Sufficient) && $substanceIds->isEmpty()) {
            $errors['supporting_substance_ids'] = 'Sufficient coverage needs at least one profile reference.';
        }

        if ($coversConstituents) {
            $constituentError = $this->constituentCoverageError($material, $substanceIds);

            if ($constituentError !== null) {
                $errors['covers_constituents'] = $constituentError;
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  Collection<int, int>  $substanceIds
     */
    private function constituentCoverageError(Ingredient|Substance $material, Collection $substanceIds): ?string
    {
        if (! $material instanceof Ingredient) {
            return 'Only ingredients have tracked constituents.';
        }

        $substanceConstituents = $material->substanceEntries()->pluck('substance_id');
        $allergenIds = $material->allergenEntries()->pluck('allergen_id');
        $allergenSubstances = Substance::query()->whereIn('allergen_id', $allergenIds)->pluck('id', 'allergen_id');

        if ($substanceConstituents->isEmpty() && $allergenIds->isEmpty()) {
            return 'No constituents are tracked for this material.';
        }

        if ($allergenIds->diff($allergenSubstances->keys())->isNotEmpty()) {
            return 'A tracked allergen has no assessed material linked to it.';
        }

        $uncovered = $substanceConstituents->merge($allergenSubstances->values())->unique()->diff($substanceIds);

        return $uncovered->isEmpty()
            ? null
            : 'Every tracked constituent must be among the profile references.';
    }
}
