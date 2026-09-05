<?php

namespace App\Actions\IngredientEnrichment;

use App\Enums\IngredientEnrichmentItemStatus;
use App\Models\Ingredient;
use App\Models\IngredientEnrichmentBatchItem;
use App\Models\IngredientIntakeItem;
use App\Models\User;
use App\Services\IngredientEnrichment\IngredientEnrichmentBatchService;
use App\Services\IngredientEnrichment\IngredientEnrichmentEvidenceReconciler;
use App\Services\IngredientEnrichment\IngredientEnrichmentPlanner;
use App\Services\IngredientEnrichment\IngredientEnrichmentResultValidator;
use App\Services\IngredientEnrichment\IngredientEnrichmentSnapshotBuilder;
use App\Services\IngredientEnrichment\IngredientEnrichmentSubjectBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class EditIngredientEnrichmentProposal
{
    public function __construct(
        private readonly IngredientEnrichmentSnapshotBuilder $snapshots,
        private readonly IngredientEnrichmentResultValidator $validator,
        private readonly IngredientEnrichmentPlanner $planner,
        private readonly IngredientEnrichmentBatchService $batches,
        private readonly IngredientEnrichmentSubjectBuilder $subjects,
        private readonly IngredientEnrichmentEvidenceReconciler $evidenceReconciler,
    ) {}

    /** @param array<string, mixed> $proposal */
    public function handle(User $actor, IngredientEnrichmentBatchItem $item, array $proposal): IngredientEnrichmentBatchItem
    {
        Gate::forUser($actor)->authorize('approve', $item->batch);

        $outcome = DB::transaction(function () use ($actor, $item, $proposal): array {
            $locked = IngredientEnrichmentBatchItem::query()->lockForUpdate()->findOrFail($item->id);
            if (! in_array($locked->status, [
                IngredientEnrichmentItemStatus::Ready,
                IngredientEnrichmentItemStatus::Warning,
                IngredientEnrichmentItemStatus::Approved,
                IngredientEnrichmentItemStatus::Rejected,
            ], true)) {
                throw ValidationException::withMessages(['item' => __('ingredient_enrichment_admin.validation.not_editable')]);
            }

            $ingredient = Ingredient::query()->withoutGlobalScopes()->lockForUpdate()->find($locked->ingredient_id);
            $intake = $locked->ingredient_intake_item_id === null
                ? null
                : IngredientIntakeItem::query()
                    ->with(['batch', 'existingIngredient'])
                    ->lockForUpdate()
                    ->find($locked->ingredient_intake_item_id);
            $subject = $intake instanceof IngredientIntakeItem
                ? $this->subjects->forIntake($intake)
                : null;
            $stale = $intake instanceof IngredientIntakeItem
                ? $subject?->fingerprint !== $locked->source_fingerprint
                    || $subject?->subjectPublicId !== (string) ($locked->snapshot['subject_public_id'] ?? '')
                : ! $ingredient
                    || $ingredient->owner_type !== null
                    || $ingredient->owner_id !== null
                    || $this->snapshots->fingerprint($ingredient) !== $locked->source_fingerprint;

            if ($stale) {
                $locked->update(['status' => IngredientEnrichmentItemStatus::Stale]);
                $this->batches->refresh($locked->ingredient_enrichment_batch_id);

                return ['item' => $locked->refresh(), 'stale' => true];
            }

            $this->assertAllowedProposal($proposal);
            $currentResult = is_array($locked->result) ? $locked->result : [];
            $currentReport = $this->validator->validateOrFail(
                $currentResult,
                $intake instanceof IngredientIntakeItem ? null : $ingredient,
            );
            $currentProposal = is_array(data_get($currentReport, 'normalized.proposal'))
                ? data_get($currentReport, 'normalized.proposal')
                : [];
            $reconciledMetadata = $this->evidenceReconciler->reconcileProposalMetadata(
                is_array($currentResult['evidence'] ?? null) ? $currentResult['evidence'] : [],
                is_array($currentResult['field_confidence'] ?? null) ? $currentResult['field_confidence'] : [],
                is_array($currentResult['value_provenance'] ?? null) ? $currentResult['value_provenance'] : [],
                $currentProposal,
                $proposal,
            );
            $candidate = [
                ...$currentResult,
                'proposal' => $proposal,
                ...$reconciledMetadata,
            ];
            $report = $this->validator->validateOrFail(
                $candidate,
                $intake instanceof IngredientIntakeItem ? null : $ingredient,
            );
            $normalized = $report['normalized'];
            $plan = $intake instanceof IngredientIntakeItem
                ? $this->planner->planForIntake(
                    $normalized,
                    $intake->existingIngredient,
                    $intake->original_current_name,
                    $intake->original_inci_name,
                    $locked->replacement_fields ?? [],
                )
                : $this->planner->plan($ingredient, $normalized, $locked->replacement_fields ?? []);
            $warnings = collect($report['warnings'])
                ->merge($normalized['warnings'])
                ->merge($normalized['unresolved_questions'])
                ->filter()
                ->unique()
                ->values()
                ->all();
            $editedFields = collect([
                ...($locked->edited_fields ?? []),
                ...$this->evidenceReconciler->changedPaths($currentProposal, $normalized['proposal'], 'proposal'),
            ])->filter(fn (mixed $path): bool => is_string($path))->unique()->sort()->values()->all();

            $locked->update([
                'status' => $warnings === [] ? IngredientEnrichmentItemStatus::Ready : IngredientEnrichmentItemStatus::Warning,
                'original_result' => $locked->original_result ?? $currentResult,
                'result' => $normalized,
                'validation_report' => $report,
                'plan' => $plan,
                'warnings' => $warnings,
                'unresolved_questions' => $normalized['unresolved_questions'],
                'edited_fields' => $editedFields,
                'edited_by_user_id' => $actor->id,
                'edited_at' => now(),
                'approved_by_user_id' => null,
                'approved_at' => null,
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

    /** @param array<string, mixed> $proposal */
    private function assertAllowedProposal(array $proposal): void
    {
        $allowed = [
            'display_name', 'inci_name', 'category', 'subcategory', 'saponification_name', 'soap_inci_naoh_name', 'soap_inci_koh_name',
            'info_markdown', 'soapmaking_relevant', 'aliases', 'identifiers', 'cosing_functions', 'translations', 'market_labels',
        ];
        if (array_diff(array_keys($proposal), $allowed) !== []) {
            throw ValidationException::withMessages([
                'proposal' => __('ingredient_enrichment_admin.validation.proposal_fields'),
            ]);
        }
    }
}
