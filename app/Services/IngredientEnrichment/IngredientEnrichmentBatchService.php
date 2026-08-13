<?php

namespace App\Services\IngredientEnrichment;

use App\Enums\IngredientEnrichmentBatchStatus;
use App\Enums\IngredientEnrichmentItemStatus;
use App\Jobs\ResearchIngredientEnrichment;
use App\Models\Ingredient;
use App\Models\IngredientEnrichmentBatch;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class IngredientEnrichmentBatchService
{
    public function __construct(
        private readonly IngredientEnrichmentInputBuilder $inputBuilder,
    ) {}

    /** @param Collection<int, Ingredient> $ingredients */
    public function start(User $actor, Collection $ingredients): IngredientEnrichmentBatch
    {
        if (! config('ingredient-enrichment.direct_ai.enabled')) {
            throw ValidationException::withMessages(['ingredients' => __('ingredient_enrichment_admin.validation.ai_disabled')]);
        }
        if (blank(config('ingredient-enrichment.openai.api_key'))) {
            throw ValidationException::withMessages(['ingredients' => __('ingredient_enrichment_admin.validation.missing_api_key')]);
        }

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

        $laravelBatch = Bus::batch(
            $batch->items()->pluck('id')->map(fn (int $id): ResearchIngredientEnrichment => new ResearchIngredientEnrichment($id))->all(),
        )->name("ingredient-enrichment:{$batch->public_id}")
            ->allowFailures()
            ->onQueue((string) config('ingredient-enrichment.direct_ai.queue'))
            ->dispatch();

        DB::transaction(function () use ($batch, $laravelBatch): void {
            $locked = IngredientEnrichmentBatch::query()->lockForUpdate()->findOrFail($batch->id);
            $locked->update([
                'laravel_batch_id' => $laravelBatch->id,
                'status' => IngredientEnrichmentBatchStatus::Processing,
                'started_at' => now(),
            ]);
        }, attempts: 5);

        return $batch->refresh()->load('items');
    }

    public function refresh(int $batchId): void
    {
        $batch = IngredientEnrichmentBatch::query()->lockForUpdate()->findOrFail($batchId);
        $counts = $batch->items()->selectRaw('status, count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');
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
            'completed_at' => $active === 0 ? now() : null,
        ]);
    }
}
