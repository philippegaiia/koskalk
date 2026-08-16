<?php

namespace App\Actions\IngredientEnrichment;

use App\Enums\IngredientDuplicateResolution;
use App\Enums\IngredientEnrichmentItemStatus;
use App\Enums\IngredientSubcategory;
use App\Models\Ingredient;
use App\Models\IngredientEnrichmentBatchItem;
use App\Models\IngredientIntakeItem;
use App\Models\User;
use App\Services\IngredientEnrichment\IngredientEnrichmentBatchService;
use App\Services\IngredientEnrichment\IngredientEnrichmentPlanner;
use App\Services\IngredientEnrichment\IngredientEnrichmentResultValidator;
use App\Services\IngredientEnrichment\IngredientEnrichmentSnapshotBuilder;
use App\Services\IngredientEnrichment\IngredientEnrichmentSubjectBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ApproveIngredientEnrichmentItem
{
    public function __construct(
        private readonly IngredientEnrichmentSnapshotBuilder $snapshots,
        private readonly IngredientEnrichmentResultValidator $validator,
        private readonly IngredientEnrichmentPlanner $planner,
        private readonly IngredientEnrichmentBatchService $batches,
        private readonly IngredientEnrichmentSubjectBuilder $subjects,
    ) {}

    /** @param list<string> $replaceFields */
    public function handle(
        User $actor,
        IngredientEnrichmentBatchItem $item,
        array $replaceFields = [],
    ): IngredientEnrichmentBatchItem {
        Gate::forUser($actor)->authorize('approve', $item->batch);

        $outcome = DB::transaction(function () use ($actor, $item, $replaceFields): array {
            $locked = IngredientEnrichmentBatchItem::query()->lockForUpdate()->findOrFail($item->id);
            if (! in_array($locked->status, [IngredientEnrichmentItemStatus::Ready, IngredientEnrichmentItemStatus::Warning], true)) {
                throw ValidationException::withMessages(['item' => __('ingredient_enrichment_admin.validation.not_approvable')]);
            }
            $ingredient = Ingredient::query()->withoutGlobalScopes()->lockForUpdate()->find($locked->ingredient_id);
            $intake = $locked->ingredient_intake_item_id === null
                ? null
                : IngredientIntakeItem::query()
                    ->with(['batch', 'existingIngredient'])
                    ->lockForUpdate()
                    ->find($locked->ingredient_intake_item_id);

            if ($intake instanceof IngredientIntakeItem) {
                $subject = $this->subjects->forIntake($intake);
                if ($subject->subjectPublicId !== (string) ($locked->snapshot['subject_public_id'] ?? '')
                    || $subject->fingerprint !== $locked->source_fingerprint) {
                    $locked->update(['status' => IngredientEnrichmentItemStatus::Stale]);
                    $this->batches->refresh($locked->ingredient_enrichment_batch_id);

                    return ['item' => $locked->refresh(), 'stale' => true];
                }
            } elseif (! $ingredient || $this->snapshots->fingerprint($ingredient) !== $locked->source_fingerprint) {
                $locked->update(['status' => IngredientEnrichmentItemStatus::Stale]);
                $this->batches->refresh($locked->ingredient_enrichment_batch_id);

                return ['item' => $locked->refresh(), 'stale' => true];
            }
            $report = $this->validator->validateOrFail($locked->result, $ingredient);
            $normalized = $report['normalized'];

            if ($intake instanceof IngredientIntakeItem
                && (($normalized['subject_type'] ?? null) !== 'intake'
                    || ($normalized['subject_public_id'] ?? null) !== (string) $intake->public_id)) {
                throw ValidationException::withMessages([
                    'subject_public_id' => __('ingredient_enrichment_admin.validation.subject_mismatch'),
                ]);
            }

            $normalizedReplace = $this->planner->normalizeReplaceFields($replaceFields);
            $plan = $intake instanceof IngredientIntakeItem
                ? $this->planner->planForIntake(
                    $normalized,
                    $intake->existingIngredient,
                    $intake->original_current_name,
                    $intake->original_inci_name,
                    $normalizedReplace,
                )
                : $this->planner->plan($ingredient, $normalized, $normalizedReplace);

            $this->assertPromotionRequirements($intake, $plan);
            $locked->update([
                'status' => IngredientEnrichmentItemStatus::Approved,
                'result' => $normalized,
                'validation_report' => $report,
                'plan' => $plan,
                'replacement_fields' => $normalizedReplace,
                'approved_by_user_id' => $actor->id,
                'approved_at' => now(),
                'rejected_by_user_id' => null,
                'rejected_at' => null,
                'rejection_reason' => null,
            ]);
            $this->batches->refresh($locked->ingredient_enrichment_batch_id);

            return ['item' => $locked->refresh(), 'stale' => false];
        }, attempts: 5);

        if ($outcome['stale']) {
            throw ValidationException::withMessages(['item' => __('ingredient_enrichment_admin.validation.stale')]);
        }

        return $outcome['item'];
    }

    /** @param array<string, mixed> $plan */
    private function assertPromotionRequirements(?IngredientIntakeItem $intake, array $plan): void
    {
        if (! $intake instanceof IngredientIntakeItem) {
            return;
        }

        $hasExactDuplicate = collect($intake->duplicate_candidates ?? [])
            ->contains(fn (mixed $candidate): bool => is_array($candidate)
                && ($candidate['match_type'] ?? null) === 'exact');

        if ($hasExactDuplicate && $intake->duplicate_resolution === null) {
            throw ValidationException::withMessages([
                'item' => __('ingredient_intake_admin.validation.duplicate_resolution_required'),
            ]);
        }

        if ($intake->duplicate_resolution === IngredientDuplicateResolution::ExistingIngredient) {
            return;
        }

        $canonical = is_array($plan['effective']['canonical'] ?? null) ? $plan['effective']['canonical'] : [];
        $category = $canonical['category'] ?? null;
        $subcategory = $canonical['subcategory'] ?? null;
        $compatible = $category === 'other'
            ? $subcategory === null
            : is_string($category) && is_string($subcategory)
                && IngredientSubcategory::tryFrom($subcategory)?->category()->value === $category;

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
