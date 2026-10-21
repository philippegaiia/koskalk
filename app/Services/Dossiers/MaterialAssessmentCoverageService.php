<?php

namespace App\Services\Dossiers;

use App\Enums\AssessmentReviewState;
use App\Enums\EvidenceType;
use App\Enums\SupplierQualificationStatus;
use App\Enums\WorkspaceModule;
use App\Models\DossierEvidenceItem;
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
     * Sufficient coverage rests on profiled references, or — for a blend recorded through component ingredients —
     * on each component's own sufficient coverage. Constituent coverage needs every tracked substance or allergen
     * among the references and every component sufficiently covered. (Supplier assessments arrive with phase 2.)
     *
     * @param  array{review_state: string, insufficient_reason?: string|null, covers_whole_material?: bool,
     *               covers_constituents?: bool, supporting_substance_ids?: list<int>,
     *               supporting_evidence_item_ids?: list<int>, note?: string|null}  $review
     */
    public function review(User $actor, Ingredient|Substance $material, array $review): MaterialAssessmentCoverage
    {
        $state = AssessmentReviewState::from($review['review_state']);
        $coversWhole = (bool) ($review['covers_whole_material'] ?? false);
        $coversConstituents = (bool) ($review['covers_constituents'] ?? false);
        $substanceIds = collect($review['supporting_substance_ids'] ?? [])->map(fn (mixed $id): int => (int) $id)->unique()->values();
        $evidenceIds = collect($review['supporting_evidence_item_ids'] ?? [])->map(fn (mixed $id): int => (int) $id)->unique()->values();

        return DB::transaction(function () use ($actor, $material, $review, $state, $coversWhole, $coversConstituents, $substanceIds, $evidenceIds): MaterialAssessmentCoverage {
            $this->lockOwningWorkspace($material);
            $this->assertCanReview($actor, $material);

            $currentProfiles = ToxicologyProfile::query()
                ->current()
                ->whereIn('substance_id', $substanceIds->all())
                ->lockForUpdate()
                ->get()
                ->keyBy('substance_id');

            $evidenceError = $this->supplierEvidenceError($material, $evidenceIds);

            if ($evidenceError !== null) {
                throw ValidationException::withMessages(['supporting_evidence_item_ids' => $evidenceError]);
            }

            $this->assertConsistent($material, $state, $review['insufficient_reason'] ?? null, $coversWhole, $coversConstituents, $substanceIds, $currentProfiles, $evidenceIds->isNotEmpty());

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

            $coverage->supportingEvidenceItems()->sync($evidenceIds->all());

            return $coverage->load(['supportingSubstances', 'supportingEvidenceItems']);
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
        bool $hasSupplierEvidence,
    ): void {
        $errors = [];

        if ($state === AssessmentReviewState::Insufficient && blank($insufficientReason)) {
            $errors['insufficient_reason'] = 'Name the uncertainty that prevents a conclusion.';
        }

        if ($state === AssessmentReviewState::Sufficient && ! $coversWhole && ! $coversConstituents) {
            $errors['covers_whole_material'] = 'Sufficient coverage needs the whole material, its constituents, or both.';
        }

        $unprofiled = $substanceIds->diff($currentProfiles->keys());
        $hasComponentEvidence = $coversConstituents && $this->hasOnlyCoveredComponents($material);

        if ($unprofiled->isNotEmpty()) {
            $errors['supporting_substance_ids'] = 'Every profile reference needs a current toxicological profile.';
        } elseif ($coversWhole && $substanceIds->isEmpty() && ! $hasSupplierEvidence) {
            $errors['supporting_substance_ids'] = 'Whole-material coverage needs at least one profile reference.';
        } elseif ($state === AssessmentReviewState::Sufficient && $substanceIds->isEmpty() && ! $hasComponentEvidence && ! $hasSupplierEvidence) {
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
        $components = $material->components()->get(['component_ingredient_id']);

        if ($substanceConstituents->isEmpty() && $allergenIds->isEmpty() && $components->isEmpty()) {
            return 'No constituents are tracked for this material.';
        }

        if ($components->contains(fn ($component): bool => $component->component_ingredient_id === null)) {
            return 'A component has no ingredient record to assess.';
        }

        $uncoveredComponents = $components->filter(fn ($component): bool => $this->stateFor(
            Ingredient::query()->findOrFail($component->component_ingredient_id),
        ) !== AssessmentReviewState::Sufficient);

        if ($uncoveredComponents->isNotEmpty()) {
            return 'Every component ingredient needs sufficient coverage of its own.';
        }

        if ($allergenIds->diff($allergenSubstances->keys())->isNotEmpty()) {
            return 'A tracked allergen has no assessed material linked to it.';
        }

        $uncovered = $substanceConstituents->merge($allergenSubstances->values())->unique()->diff($substanceIds);

        return $uncovered->isEmpty()
            ? null
            : 'Every tracked constituent must be among the profile references.';
    }

    /**
     * Supplier safety assessments may support a private ingredient's coverage when each is current and attached to a
     * validated qualification of that same ingredient.
     *
     * @param  Collection<int, int>  $evidenceIds
     */
    private function supplierEvidenceError(Ingredient|Substance $material, Collection $evidenceIds): ?string
    {
        if ($evidenceIds->isEmpty()) {
            return null;
        }

        if (! $material instanceof Ingredient || $material->workspace_id === null) {
            return 'Supplier assessments can only support the coverage of a private ingredient.';
        }

        $valid = DossierEvidenceItem::query()
            ->whereIn('id', $evidenceIds->all())
            ->where('workspace_id', $material->workspace_id)
            ->where('type', EvidenceType::SupplierSafetyAssessment->value)
            ->whereNull('retired_at')
            ->whereHas('supplierQualification', fn ($query) => $query
                ->where('ingredient_id', $material->id)
                ->where('status', SupplierQualificationStatus::Valide->value))
            ->count();

        return $valid === $evidenceIds->count()
            ? null
            : 'Each supplier assessment must be current and belong to a validated supplier of this ingredient.';
    }

    /**
     * A blend recorded only through component ingredients, each with sufficient coverage of its own:
     * the components' coverage is the evidence, so no direct profile reference is needed.
     */
    private function hasOnlyCoveredComponents(Ingredient|Substance $material): bool
    {
        return $material instanceof Ingredient
            && $material->components()->exists()
            && ! $material->substanceEntries()->exists()
            && ! $material->allergenEntries()->exists()
            && $this->constituentCoverageError($material, collect()) === null;
    }
}
