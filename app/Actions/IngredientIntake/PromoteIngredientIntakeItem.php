<?php

namespace App\Actions\IngredientIntake;

use App\Enums\IngredientDuplicateResolution;
use App\Enums\IngredientEnrichmentItemStatus;
use App\Enums\IngredientIntakeItemStatus;
use App\Enums\IngredientSubcategory;
use App\Enums\Visibility;
use App\Models\Ingredient;
use App\Models\IngredientEnrichmentBatchItem;
use App\Models\IngredientIntakeItem;
use App\Models\User;
use App\Services\IngredientDataEntryService;
use App\Services\IngredientEnrichment\ApplyPlatformIngredientEnrichment;
use App\Services\IngredientEnrichment\IngredientEnrichmentBatchService;
use App\Services\IngredientEnrichment\IngredientEnrichmentPlanner;
use App\Services\IngredientEnrichment\IngredientEnrichmentResultValidator;
use App\Services\IngredientEnrichment\IngredientEnrichmentSnapshotBuilder;
use App\Services\IngredientEnrichment\IngredientEnrichmentSubjectBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class PromoteIngredientIntakeItem
{
    public function __construct(
        private readonly IngredientEnrichmentSnapshotBuilder $snapshots,
        private readonly IngredientEnrichmentSubjectBuilder $subjects,
        private readonly IngredientEnrichmentResultValidator $validator,
        private readonly IngredientEnrichmentPlanner $planner,
        private readonly IngredientEnrichmentBatchService $batches,
        private readonly IngredientDataEntryService $dataEntry,
        private readonly ApplyPlatformIngredientEnrichment $applier,
    ) {}

    public function handle(User $actor, IngredientEnrichmentBatchItem $item): IngredientEnrichmentBatchItem
    {
        Gate::forUser($actor)->authorize('apply', $item->batch);

        return DB::transaction(function () use ($actor, $item): IngredientEnrichmentBatchItem {
            $locked = IngredientEnrichmentBatchItem::query()
                ->with(['batch', 'intakeItem'])
                ->lockForUpdate()
                ->findOrFail($item->id);

            if ($locked->status === IngredientEnrichmentItemStatus::Applied
                && $locked->intakeItem?->promoted_ingredient_id !== null) {
                return $locked->refresh();
            }

            if ($locked->ingredient_intake_item_id === null) {
                throw ValidationException::withMessages([
                    'item' => __('ingredient_enrichment_admin.validation.intake_target_required'),
                ]);
            }

            if ($locked->status !== IngredientEnrichmentItemStatus::Approved) {
                throw ValidationException::withMessages([
                    'item' => __('ingredient_enrichment_admin.validation.not_promotable'),
                ]);
            }

            $intake = IngredientIntakeItem::query()
                ->with(['batch', 'existingIngredient'])
                ->lockForUpdate()
                ->findOrFail($locked->ingredient_intake_item_id);

            $hasExactDuplicate = collect($intake->duplicate_candidates ?? [])
                ->contains(fn (mixed $candidate): bool => is_array($candidate)
                    && ($candidate['match_type'] ?? null) === 'exact');

            if ($hasExactDuplicate && $intake->duplicate_resolution === null) {
                throw ValidationException::withMessages([
                    'item' => __('ingredient_intake_admin.validation.duplicate_resolution_required'),
                ]);
            }

            $subject = $this->subjects->forIntake($intake);

            if ($subject->fingerprint !== $locked->source_fingerprint
                || $subject->subjectPublicId !== (string) ($locked->snapshot['subject_public_id'] ?? '')) {
                throw ValidationException::withMessages([
                    'item' => __('ingredient_enrichment_admin.validation.stale'),
                ]);
            }

            $report = $this->validator->validateOrFail($locked->result);
            $normalized = $report['normalized'];
            $replaceFields = $this->planner->normalizeReplaceFields($locked->replacement_fields ?? []);
            $linked = $intake->duplicate_resolution === IngredientDuplicateResolution::ExistingIngredient
                ? Ingredient::withoutGlobalScopes()->lockForUpdate()->find($intake->existing_ingredient_id)
                : null;

            if ($intake->duplicate_resolution === IngredientDuplicateResolution::ExistingIngredient && ! $linked instanceof Ingredient) {
                throw ValidationException::withMessages([
                    'item' => __('ingredient_intake_admin.validation.duplicate_existing_required'),
                ]);
            }

            $plan = $this->planner->planForIntake(
                $normalized,
                $linked,
                $intake->original_current_name,
                $intake->original_inci_name,
                $replaceFields,
            );
            $this->assertPromotionRequirements($plan, $linked);
            $reviewerId = $locked->approved_by_user_id ?? $actor->id;
            $reviewedAt = $locked->approved_at;

            if ($linked instanceof Ingredient) {
                $applyResult = [
                    ...$normalized,
                    'subject_type' => 'ingredient',
                    'subject_public_id' => (string) $linked->public_id,
                    'catalog_key' => $linked->catalog_key,
                    'source_fingerprint' => $this->snapshots->fingerprint($linked),
                ];
                $plan = $this->planner->plan($linked, $applyResult, $replaceFields);
                $applied = $this->applier->applyWithinTransaction(
                    $plan,
                    $applyResult,
                    $replaceFields,
                    promotion: true,
                    reviewerId: $reviewerId,
                    reviewedAt: $reviewedAt,
                );
                $intakeStatus = IngredientIntakeItemStatus::LinkedExisting;
                $promotedId = $applied['ingredient']->id;
            } else {
                $catalogKey = $this->dataEntry->generateCatalogKey('ADM');
                $canonical = is_array($plan['effective']['canonical'] ?? null) ? $plan['effective']['canonical'] : [];
                $created = Ingredient::withoutGlobalScopes()->create([
                    'catalog_key' => $catalogKey,
                    'category' => $canonical['category'],
                    'subcategory' => $canonical['subcategory'],
                    'display_name' => $canonical['display_name'],
                    'inci_name' => $canonical['inci_name'],
                    'owner_type' => null,
                    'owner_id' => null,
                    'workspace_id' => null,
                    'visibility' => Visibility::Public,
                    'requires_admin_review' => true,
                    'is_active' => false,
                    'is_manufactured' => false,
                    'requires_aromatic_compliance' => false,
                    'is_soap_saponification_trusted' => false,
                    'taxonomy_source' => 'admin_reviewed_enrichment',
                ]);
                $plan['ingredient_id'] = $created->id;
                $plan['catalog_key'] = $catalogKey;
                $applied = $this->applier->applyWithinTransaction(
                    $plan,
                    $normalized,
                    $replaceFields,
                    promotion: true,
                    reviewerId: $reviewerId,
                    reviewedAt: $reviewedAt,
                );
                $intakeStatus = IngredientIntakeItemStatus::Promoted;
                $promotedId = $applied['ingredient']->id;
            }

            $intake->update([
                'status' => $intakeStatus,
                'promoted_ingredient_id' => $promotedId,
                'promoted_by_user_id' => $actor->id,
                'promoted_at' => now(),
                'failure_code' => null,
                'failure_message' => null,
            ]);
            $locked->update([
                'status' => IngredientEnrichmentItemStatus::Applied,
                'catalog_key' => $applied['ingredient']->catalog_key,
                'applied_by_user_id' => $actor->id,
                'applied_at' => now(),
                'failure_code' => null,
                'failure_message' => null,
            ]);
            $this->batches->refreshIntake($intake->ingredient_intake_batch_id);
            $this->batches->refresh($locked->ingredient_enrichment_batch_id);

            return $locked->refresh();
        }, attempts: 5);
    }

    /** @param array<string, mixed> $plan */
    private function assertPromotionRequirements(array $plan, ?Ingredient $linked): void
    {
        if ($linked instanceof Ingredient) {
            return;
        }

        $canonical = is_array($plan['effective']['canonical'] ?? null) ? $plan['effective']['canonical'] : [];
        $category = $canonical['category'] ?? null;
        $subcategory = $canonical['subcategory'] ?? null;
        $subcategoryEnum = is_string($subcategory)
            ? IngredientSubcategory::tryFrom($subcategory)
            : null;
        $compatible = $category === 'other'
            ? $subcategory === null
            : is_string($category) && $subcategoryEnum?->category()->value === $category;

        if (! is_string($canonical['display_name'] ?? null) || trim($canonical['display_name']) === '') {
            throw ValidationException::withMessages([
                'proposal.display_name' => __('ingredient_enrichment_admin.validation.promotion_display_name_required'),
            ]);
        }

        if (! is_string($category) || ! $compatible) {
            throw ValidationException::withMessages([
                'proposal.category' => __('ingredient_enrichment_admin.validation.promotion_taxonomy_required'),
            ]);
        }
    }
}
