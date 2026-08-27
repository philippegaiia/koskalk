<?php

namespace App\Services\IngredientEnrichment;

use App\Enums\IngredientEnrichmentBatchMode;
use App\Enums\IngredientEnrichmentBatchStatus;
use App\Enums\IngredientEnrichmentItemStatus;
use App\Enums\IngredientIntakeBatchStatus;
use App\Enums\IngredientIntakeItemStatus;
use App\Jobs\GenerateIngredientGuidanceRefresh;
use App\Jobs\ResearchIngredientEnrichment;
use App\Models\Ingredient;
use App\Models\IngredientEnrichmentBatch;
use App\Models\IngredientEnrichmentBatchItem;
use App\Models\IngredientIntakeBatch;
use App\Models\IngredientIntakeItem;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class IngredientEnrichmentBatchService
{
    public function __construct(
        private readonly IngredientEnrichmentInputBuilder $inputBuilder,
        private readonly IngredientEnrichmentSubjectBuilder $subjectBuilder,
        private readonly IngredientGuidanceContextBuilder $guidanceContext,
    ) {}

    /** @param Collection<int, Ingredient> $ingredients */
    public function start(User $actor, Collection $ingredients): IngredientEnrichmentBatch
    {
        $this->assertConfigured();

        $ids = $ingredients->pluck('id')->filter()->unique()->sort()->values();
        $maximum = (int) config('ingredient-enrichment.direct_ai.maximum_batch_size');
        if ($ids->isEmpty() || $ids->count() > $maximum) {
            throw ValidationException::withMessages(['ingredients' => __('ingredient_enrichment_admin.validation.selection_size', ['maximum' => $maximum])]);
        }

        $batch = DB::transaction(function () use ($actor, $ids): IngredientEnrichmentBatch {
            $locked = Ingredient::query()->withoutGlobalScopes()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            if ($locked->count() !== $ids->count() || $locked->contains(fn (Ingredient $ingredient): bool => $ingredient->owner_type !== null || $ingredient->owner_id !== null)) {
                throw ValidationException::withMessages(['ingredients' => __('ingredient_enrichment_admin.validation.platform_only')]);
            }

            $batch = IngredientEnrichmentBatch::query()->create([
                'requested_by_user_id' => $actor->id,
                'status' => IngredientEnrichmentBatchStatus::Pending,
                'model' => config('ingredient-enrichment.openai.model'),
                'reasoning_effort' => config('ingredient-enrichment.openai.reasoning_effort'),
                'prompt_version' => config('ingredient-enrichment.openai.prompt_version'),
                'schema_version' => config('ingredient-enrichment.schema_version'),
                'total_count' => $locked->count(),
                'pending_count' => $locked->count(),
            ]);

            foreach ($locked as $ingredient) {
                $record = $this->inputBuilder->build($ingredient);
                $batch->items()->create([
                    'ingredient_id' => $ingredient->id,
                    'catalog_key' => $ingredient->catalog_key,
                    'snapshot' => $record,
                    'source_fingerprint' => $record['source_fingerprint'],
                ]);
            }

            return $batch;
        }, attempts: 5);

        $this->dispatch($batch);

        return $batch->refresh()->load('items');
    }

    /** @param Collection<int, Ingredient> $ingredients */
    public function startGuidanceRefresh(
        User $actor,
        Collection $ingredients,
        bool $localizationOnly = false,
    ): IngredientEnrichmentBatch {
        $this->assertConfigured();

        $ids = $ingredients->pluck('id')->filter()->unique()->sort()->values();
        $maximum = (int) config('ingredient-enrichment.direct_ai.maximum_batch_size');
        if ($ids->isEmpty() || $ids->count() > $maximum) {
            throw ValidationException::withMessages([
                'ingredients' => __('ingredient_enrichment_admin.validation.selection_size', ['maximum' => $maximum]),
            ]);
        }

        $mode = $localizationOnly
            ? IngredientEnrichmentBatchMode::GuidanceLocalization
            : IngredientEnrichmentBatchMode::GuidanceRefresh;
        $promptVersion = $localizationOnly
            ? config('ingredient-enrichment.openai.guidance_localization_prompt_version')
            : config('ingredient-enrichment.openai.guidance_prompt_version');

        $batch = DB::transaction(function () use ($actor, $ids, $mode, $promptVersion): IngredientEnrichmentBatch {
            $locked = Ingredient::query()
                ->withoutGlobalScopes()
                ->whereIn('id', $ids)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            if ($locked->count() !== $ids->count()
                || $locked->contains(fn (Ingredient $ingredient): bool => $ingredient->owner_type !== null || $ingredient->owner_id !== null)) {
                throw ValidationException::withMessages([
                    'ingredients' => __('ingredient_enrichment_admin.validation.platform_only'),
                ]);
            }

            $batch = IngredientEnrichmentBatch::query()->create([
                'requested_by_user_id' => $actor->id,
                'status' => IngredientEnrichmentBatchStatus::Pending,
                'model' => config('ingredient-enrichment.openai.model'),
                'reasoning_effort' => config('ingredient-enrichment.openai.reasoning_effort'),
                'prompt_version' => $promptVersion,
                'schema_version' => 1,
                'mode' => $mode,
                'total_count' => $locked->count(),
                'pending_count' => $locked->count(),
            ]);

            foreach ($locked as $ingredient) {
                $context = $this->guidanceContext->build($ingredient);
                $batch->items()->create([
                    'ingredient_id' => $ingredient->id,
                    'catalog_key' => $ingredient->catalog_key,
                    'snapshot' => $context,
                    'source_fingerprint' => $context['source_fingerprint'],
                    'warnings' => $context['warnings'] ?? [],
                ]);
            }

            return $batch;
        }, attempts: 5);

        $this->dispatch($batch);

        return $batch->refresh()->load('items');
    }

    public function startIntake(User $actor, IngredientIntakeBatch $intakeBatch): IngredientEnrichmentBatch
    {
        $this->assertConfigured();

        /** @var array{batch_id:int, item_ids:list<int>} $prepared */
        $prepared = DB::transaction(function () use ($actor, $intakeBatch): array {
            $lockedIntake = IngredientIntakeBatch::query()
                ->lockForUpdate()
                ->findOrFail($intakeBatch->id);
            $existingBatch = $lockedIntake->ingredient_enrichment_batch_id === null
                ? null
                : IngredientEnrichmentBatch::query()
                    ->lockForUpdate()
                    ->find($lockedIntake->ingredient_enrichment_batch_id);
            $batch = $existingBatch ?? IngredientEnrichmentBatch::query()->create([
                'requested_by_user_id' => $actor->id,
                'status' => IngredientEnrichmentBatchStatus::Pending,
                'model' => config('ingredient-enrichment.openai.model'),
                'reasoning_effort' => config('ingredient-enrichment.openai.reasoning_effort'),
                'prompt_version' => config('ingredient-enrichment.openai.prompt_version'),
                'schema_version' => config('ingredient-enrichment.schema_version'),
                'mode' => 'intake',
            ]);
            $itemIds = [];

            $eligible = IngredientIntakeItem::query()
                ->where('ingredient_intake_batch_id', $lockedIntake->id)
                ->whereIn('status', [
                    IngredientIntakeItemStatus::Draft->value,
                    IngredientIntakeItemStatus::Failed->value,
                    IngredientIntakeItemStatus::Queued->value,
                ])
                ->orderBy('row_number')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($eligible as $intakeItem) {
                if ($this->hasUnresolvedExactDuplicate($intakeItem)) {
                    continue;
                }

                $enrichmentItem = IngredientEnrichmentBatchItem::query()
                    ->where('ingredient_enrichment_batch_id', $batch->id)
                    ->where('ingredient_intake_item_id', $intakeItem->id)
                    ->lockForUpdate()
                    ->first();

                if ($enrichmentItem instanceof IngredientEnrichmentBatchItem) {
                    if (! in_array($enrichmentItem->status, [
                        IngredientEnrichmentItemStatus::Failed,
                        IngredientEnrichmentItemStatus::Stale,
                        IngredientEnrichmentItemStatus::Cancelled,
                    ], true)) {
                        continue;
                    }

                    $enrichmentItem->update([
                        'status' => IngredientEnrichmentItemStatus::Pending,
                        'failure_code' => null,
                        'failure_message' => null,
                    ]);
                    $itemIds[] = $enrichmentItem->id;
                    $intakeItem->update(['status' => IngredientIntakeItemStatus::Queued]);

                    continue;
                }

                $subject = $this->subjectBuilder->forIntake($intakeItem);
                $record = $this->inputBuilder->buildForSubject($subject);
                $enrichmentItem = $batch->items()->create([
                    'ingredient_intake_item_id' => $intakeItem->id,
                    'catalog_key' => null,
                    'snapshot' => $record,
                    'source_fingerprint' => $record['source_fingerprint'],
                ]);
                $itemIds[] = $enrichmentItem->id;
                $intakeItem->update(['status' => IngredientIntakeItemStatus::Queued]);
            }

            if ($existingBatch === null && $itemIds === []) {
                throw ValidationException::withMessages([
                    'items' => __('ingredient_intake_admin.validation.no_eligible_rows'),
                ]);
            }

            $lockedIntake->update([
                'ingredient_enrichment_batch_id' => $batch->id,
                'status' => $itemIds === []
                    ? $lockedIntake->status
                    : IngredientIntakeBatchStatus::Researching,
            ]);
            $this->refreshIntake($lockedIntake->id);
            $batch->update([
                'total_count' => $batch->items()->count(),
                'pending_count' => $batch->items()->where('status', IngredientEnrichmentItemStatus::Pending)->count(),
            ]);

            return [
                'batch_id' => $batch->id,
                'item_ids' => array_values(array_unique($itemIds)),
            ];
        }, attempts: 5);

        $batch = IngredientEnrichmentBatch::query()->findOrFail($prepared['batch_id']);
        if ($prepared['item_ids'] !== []) {
            $this->dispatch($batch, $prepared['item_ids']);
        }

        return $batch->refresh()->load('items');
    }

    /**
     * @param  list<int>|null  $itemIds
     */
    public function dispatch(IngredientEnrichmentBatch $batch, ?array $itemIds = null): void
    {
        $items = $batch->items()
            ->when($itemIds !== null, fn ($query) => $query->whereIn('id', $itemIds))
            ->where('status', IngredientEnrichmentItemStatus::Pending)
            ->get();

        if ($items->isEmpty()) {
            return;
        }

        $jobs = $items
            ->map(function (IngredientEnrichmentBatchItem $item) use ($batch): object {
                if ($batch->mode instanceof IngredientEnrichmentBatchMode && $batch->mode->isGuidance()) {
                    return new GenerateIngredientGuidanceRefresh(
                        $item->id,
                        $batch->mode->isLocalizationOnly(),
                    );
                }

                return new ResearchIngredientEnrichment(
                    $item->id,
                    (bool) data_get($item->snapshot, 'research_rules.allow_gap_research', false),
                );
            })
            ->all();

        try {
            $laravelBatch = Bus::batch($jobs)
                ->name("ingredient-enrichment:{$batch->public_id}")
                ->allowFailures()
                ->onQueue((string) config('ingredient-enrichment.direct_ai.queue'))
                ->dispatch();
        } catch (Throwable $exception) {
            report($exception);
            DB::transaction(function () use ($batch, $items): void {
                $lockedBatch = IngredientEnrichmentBatch::query()->lockForUpdate()->findOrFail($batch->id);
                foreach ($items as $item) {
                    $item->update([
                        'status' => IngredientEnrichmentItemStatus::Failed,
                        'failure_code' => 'dispatch_failed',
                        'failure_message' => __('ingredient_enrichment_admin.validation.provider_failed'),
                    ]);
                }
                $lockedBatch->update(['status' => IngredientEnrichmentBatchStatus::PartiallyFailed]);
            }, attempts: 5);

            throw $exception;
        }

        DB::transaction(function () use ($batch, $laravelBatch): void {
            $locked = IngredientEnrichmentBatch::query()->lockForUpdate()->findOrFail($batch->id);
            $locked->update([
                'laravel_batch_id' => $laravelBatch->id,
                'status' => IngredientEnrichmentBatchStatus::Processing,
                'started_at' => $locked->started_at ?? now(),
            ]);
        }, attempts: 5);
    }

    public function assertConfigured(): void
    {
        if (! config('ingredient-enrichment.direct_ai.enabled')) {
            throw ValidationException::withMessages(['ingredients' => __('ingredient_enrichment_admin.validation.ai_disabled')]);
        }
        if (blank(config('ingredient-enrichment.openai.api_key'))) {
            throw ValidationException::withMessages(['ingredients' => __('ingredient_enrichment_admin.validation.missing_api_key')]);
        }
    }

    public function refreshIntake(int $intakeBatchId): void
    {
        $batch = IngredientIntakeBatch::query()->lockForUpdate()->find($intakeBatchId);
        if (! $batch instanceof IngredientIntakeBatch) {
            return;
        }

        $counts = $batch->items()
            ->reorder()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');
        $fields = [
            'draft_count' => IngredientIntakeItemStatus::Draft->value,
            'needs_resolution_count' => IngredientIntakeItemStatus::NeedsResolution->value,
            'queued_count' => IngredientIntakeItemStatus::Queued->value,
            'researching_count' => IngredientIntakeItemStatus::Researching->value,
            'ready_count' => IngredientIntakeItemStatus::Ready->value,
            'failed_count' => IngredientIntakeItemStatus::Failed->value,
            'approved_count' => IngredientIntakeItemStatus::Approved->value,
            'promoted_count' => IngredientIntakeItemStatus::Promoted->value,
            'rejected_count' => IngredientIntakeItemStatus::Rejected->value,
        ];
        $values = collect($fields)->mapWithKeys(
            fn (string $status, string $field): array => [$field => (int) ($counts[$status] ?? 0)],
        )->all();
        $active = $values['queued_count'] + $values['researching_count'];
        $hasReviewableRows = $values['ready_count'] + $values['failed_count'] + $values['approved_count']
            + $values['promoted_count'] + $values['rejected_count'] > 0;
        $batch->update([
            ...$values,
            'total_count' => $batch->items()->count(),
            'status' => $active > 0
                ? IngredientIntakeBatchStatus::Researching
                : ($hasReviewableRows ? IngredientIntakeBatchStatus::ReadyForReview : $batch->status),
        ]);
    }

    public function refresh(int $batchId): void
    {
        $batch = IngredientEnrichmentBatch::query()->lockForUpdate()->findOrFail($batchId);
        $counts = $batch->items()->reorder()->selectRaw('status, count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');
        $values = collect(IngredientEnrichmentItemStatus::cases())->mapWithKeys(
            fn (IngredientEnrichmentItemStatus $status): array => ["{$status->value}_count" => (int) ($counts[$status->value] ?? 0)],
        )->all();
        $active = $values['pending_count'] + $values['researching_count'] + $values['applying_count'];
        $status = $active > 0
            ? IngredientEnrichmentBatchStatus::Processing
            : ($values['failed_count'] > 0 ? IngredientEnrichmentBatchStatus::PartiallyFailed : IngredientEnrichmentBatchStatus::ReadyForReview);
        unset($values['applying_count']);
        $batch->update([
            ...$values,
            'status' => $status,
            'input_tokens' => (int) $batch->items()->sum('input_tokens'),
            'output_tokens' => (int) $batch->items()->sum('output_tokens'),
            'web_search_calls' => (int) $batch->items()->sum('web_search_calls'),
            'structured_source_calls' => (int) $batch->items()->sum('structured_source_calls'),
            'completed_at' => $active === 0 ? now() : null,
        ]);
    }

    private function hasUnresolvedExactDuplicate(IngredientIntakeItem $item): bool
    {
        return $item->duplicate_resolution === null
            && collect($item->duplicate_candidates ?? [])
                ->contains(fn (mixed $candidate): bool => is_array($candidate)
                    && ($candidate['match_type'] ?? null) === 'exact');
    }
}
