<?php

namespace App\Actions\IngredientIntake;

use App\Enums\IngredientDuplicateResolution;
use App\Enums\IngredientEnrichmentItemStatus;
use App\Enums\IngredientIntakeItemStatus;
use App\Models\Ingredient;
use App\Models\IngredientEnrichmentBatchItem;
use App\Models\IngredientIntakeBatch;
use App\Models\IngredientIntakeItem;
use App\Models\User;
use App\Services\IngredientEnrichment\IngredientEnrichmentInputBuilder;
use App\Services\IngredientEnrichment\IngredientEnrichmentSubjectBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ResolveIngredientIntakeDuplicate
{
    public function __construct(
        private readonly IngredientEnrichmentInputBuilder $inputBuilder,
        private readonly IngredientEnrichmentSubjectBuilder $subjectBuilder,
    ) {}

    public function handle(
        User $actor,
        IngredientIntakeItem $item,
        IngredientDuplicateResolution $resolution,
        ?Ingredient $existingIngredient = null,
    ): IngredientIntakeItem {
        Gate::forUser($actor)->authorize('update', $item->batch);

        return DB::transaction(function () use ($item, $resolution, $existingIngredient): IngredientIntakeItem {
            $lockedItem = IngredientIntakeItem::query()
                ->with('batch')
                ->lockForUpdate()
                ->findOrFail($item->id);

            if (! $lockedItem->batch instanceof IngredientIntakeBatch) {
                throw ValidationException::withMessages([
                    'item' => __('ingredient_intake_admin.validation.duplicate_resolution_required'),
                ]);
            }

            $existingId = null;

            if ($resolution === IngredientDuplicateResolution::ExistingIngredient) {
                if (! $existingIngredient instanceof Ingredient) {
                    throw ValidationException::withMessages([
                        'existing_ingredient' => __('ingredient_intake_admin.validation.duplicate_existing_required'),
                    ]);
                }

                $isCandidate = collect($lockedItem->duplicate_candidates ?? [])
                    ->contains(fn (mixed $candidate): bool => is_array($candidate)
                        && ($candidate['candidate_type'] ?? null) === 'ingredient'
                        && (int) ($candidate['ingredient_id'] ?? 0) === $existingIngredient->id);

                if (! $isCandidate) {
                    throw ValidationException::withMessages([
                        'existing_ingredient' => __('ingredient_intake_admin.validation.duplicate_existing_required'),
                    ]);
                }

                $existingId = $existingIngredient->id;
            }

            $changed = $lockedItem->duplicate_resolution !== $resolution
                || $lockedItem->existing_ingredient_id !== $existingId;

            $lockedItem->update([
                'duplicate_resolution' => $resolution,
                'existing_ingredient_id' => $existingId,
                'status' => IngredientIntakeItemStatus::Draft,
                'failure_code' => null,
                'failure_message' => null,
            ]);

            if ($changed) {
                $this->invalidateResearch($lockedItem->fresh()->load(['batch', 'existingIngredient']));
            }

            return $lockedItem->refresh();
        }, attempts: 5);
    }

    private function invalidateResearch(IngredientIntakeItem $intakeItem): void
    {
        IngredientEnrichmentBatchItem::query()
            ->where('ingredient_intake_item_id', $intakeItem->id)
            ->lockForUpdate()
            ->get()
            ->each(function (IngredientEnrichmentBatchItem $enrichmentItem) use ($intakeItem): void {
                $subject = $this->subjectBuilder->forIntake($intakeItem);
                $snapshot = $this->inputBuilder->buildForSubject($subject);

                $enrichmentItem->update([
                    'status' => IngredientEnrichmentItemStatus::Stale,
                    'snapshot' => $snapshot,
                    'source_fingerprint' => $subject->fingerprint,
                    'result' => null,
                    'validation_report' => null,
                    'plan' => null,
                    'replacement_fields' => null,
                    'confidence' => null,
                    'warnings' => [],
                    'unresolved_questions' => [],
                    'sources' => [],
                    'research_stages' => [],
                    'original_result' => null,
                    'edited_fields' => [],
                    'edited_by_user_id' => null,
                    'edited_at' => null,
                    'provider_response_id' => null,
                    'provider_request_id' => null,
                    'provider_model' => null,
                    'input_tokens' => 0,
                    'output_tokens' => 0,
                    'web_search_calls' => 0,
                    'structured_source_calls' => 0,
                    'failure_code' => null,
                    'failure_message' => null,
                    'approved_by_user_id' => null,
                    'applied_by_user_id' => null,
                    'research_started_at' => null,
                    'research_completed_at' => null,
                    'approved_at' => null,
                    'rejected_by_user_id' => null,
                    'rejected_at' => null,
                    'rejection_reason' => null,
                    'applied_at' => null,
                ]);
            });
    }
}
