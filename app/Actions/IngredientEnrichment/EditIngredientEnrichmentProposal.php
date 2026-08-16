<?php

namespace App\Actions\IngredientEnrichment;

use App\Enums\IngredientEnrichmentItemStatus;
use App\Models\Ingredient;
use App\Models\IngredientEnrichmentBatchItem;
use App\Models\IngredientIntakeItem;
use App\Models\User;
use App\Services\IngredientEnrichment\IngredientEnrichmentBatchService;
use App\Services\IngredientEnrichment\IngredientEnrichmentPlanner;
use App\Services\IngredientEnrichment\IngredientEnrichmentResultValidator;
use App\Services\IngredientEnrichment\IngredientEnrichmentSnapshotBuilder;
use App\Services\IngredientEnrichment\IngredientEnrichmentSubjectBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EditIngredientEnrichmentProposal
{
    public function __construct(
        private readonly IngredientEnrichmentSnapshotBuilder $snapshots,
        private readonly IngredientEnrichmentResultValidator $validator,
        private readonly IngredientEnrichmentPlanner $planner,
        private readonly IngredientEnrichmentBatchService $batches,
        private readonly IngredientEnrichmentSubjectBuilder $subjects,
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
            $candidate = [
                ...$currentResult,
                'proposal' => $proposal,
                'evidence' => $this->synchronizeSourceEvidence($currentResult['evidence'] ?? [], $proposal),
                'field_confidence' => $this->synchronizeFieldConfidence($currentResult['field_confidence'] ?? [], $proposal),
                'value_provenance' => $this->synchronizeValueProvenance(
                    $currentResult['value_provenance'] ?? [],
                    $currentProposal,
                    $proposal,
                ),
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
                ...$this->changedPaths($currentProposal, $normalized['proposal'], 'proposal'),
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

    /**
     * @param  array<string|int, mixed>  $before
     * @param  array<string|int, mixed>  $after
     * @return list<string>
     */
    private function changedPaths(array $before, array $after, string $prefix): array
    {
        $paths = [];
        foreach (collect([...array_keys($before), ...array_keys($after)])->unique() as $key) {
            $path = $prefix.'.'.$key;
            $old = $before[$key] ?? null;
            $new = $after[$key] ?? null;
            if (is_array($old) && is_array($new)) {
                $paths = [...$paths, ...$this->changedPaths($old, $new, $path)];
            } elseif ($old !== $new) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /**
     * @param  array<int, mixed>  $evidence
     * @param  array<string, mixed>  $proposal
     * @return list<array<string, mixed>>
     */
    private function synchronizeSourceEvidence(array $evidence, array $proposal): array
    {
        $sourceFields = [
            'source_name', 'source_url', 'source_tier', 'confidence', 'source_version',
            'source_updated_at', 'retrieved_at',
        ];
        $preserved = collect($evidence)
            ->filter(fn (mixed $row): bool => is_array($row) && is_string($row['field'] ?? null))
            ->reject(fn (array $row): bool => $this->isSourceBackedCollectionPath($row['field']));

        return $preserved->merge($this->sourceBackedRows($proposal)->map(
            fn (array $row): array => [
                'field' => $row['field'],
                ...collect($row['source'])->only($sourceFields)->all(),
            ],
        ))->values()->all();
    }

    /**
     * @param  array<int, mixed>  $fieldConfidence
     * @param  array<string, mixed>  $proposal
     * @return list<array{field: string, confidence: mixed}>
     */
    private function synchronizeFieldConfidence(array $fieldConfidence, array $proposal): array
    {
        $preserved = collect($fieldConfidence)
            ->filter(fn (mixed $row): bool => is_array($row) && is_string($row['field'] ?? null))
            ->reject(fn (array $row): bool => $this->isSourceBackedCollectionPath($row['field']));

        return $preserved->merge($this->sourceBackedRows($proposal)->map(fn (array $row): array => [
            'field' => $row['field'],
            'confidence' => $row['source']['confidence'] ?? null,
        ]))->values()->all();
    }

    /**
     * @param  array<string, mixed>  $proposal
     * @return Collection<int, array{field: string, source: array<string, mixed>}>
     */
    private function sourceBackedRows(array $proposal): Collection
    {
        return collect(['aliases', 'identifiers', 'cosing_functions', 'market_labels'])
            ->flatMap(fn (string $collection): array => collect(is_array($proposal[$collection] ?? null) ? $proposal[$collection] : [])
                ->filter(fn (mixed $row): bool => is_array($row))
                ->values()
                ->map(fn (array $row, int $index): array => [
                    'field' => "proposal.{$collection}.{$index}",
                    'source' => $row,
                ])->all())
            ->values();
    }

    private function isSourceBackedCollectionPath(string $path): bool
    {
        return preg_match('/^proposal\.(aliases|identifiers|cosing_functions|market_labels)\.\d+$/', $path) === 1;
    }

    /**
     * @param  array<int, mixed>  $provenance
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return list<array<string, mixed>>
     */
    private function synchronizeValueProvenance(array $provenance, array $before, array $after): array
    {
        $rows = collect($provenance)
            ->filter(fn (mixed $row): bool => is_array($row) && is_string($row['field'] ?? null))
            ->keyBy('field');

        foreach ($this->changedPaths($before, $after, 'proposal') as $path) {
            if ($this->isFormattingOnlyChange($path, data_get($before, Str::after($path, 'proposal.')), data_get($after, Str::after($path, 'proposal.')))) {
                continue;
            }

            $field = $this->provenancePath($path);
            $rows->put($field, [
                'field' => $field,
                'kind' => 'reviewer_supplied',
                'reasoning' => 'Changed explicitly by the reviewing Admin.',
                'source_urls' => [],
            ]);
        }

        return $rows->values()->all();
    }

    private function isFormattingOnlyChange(string $path, mixed $before, mixed $after): bool
    {
        if (! preg_match('/^proposal\.(inci_name|market_labels\.\d+\.declaration_name)$/', $path)) {
            return false;
        }

        return is_string($before) && is_string($after)
            && Str::lower(Str::squish($before)) === Str::lower(Str::squish($after));
    }

    private function provenancePath(string $path): string
    {
        if (preg_match('/^(proposal\.(?:aliases|identifiers|cosing_functions|market_labels|translations)\.\d+)/', $path, $matches) === 1) {
            return $matches[1];
        }

        return $path;
    }
}
