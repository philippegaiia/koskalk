<?php

namespace App\Services\IngredientEnrichment;

use App\Enums\IngredientEnrichmentBatchMode;
use App\Enums\IngredientEnrichmentItemStatus;
use App\Enums\IngredientTranslationOrigin;
use App\Models\Ingredient;
use App\Models\IngredientEnrichmentBatch;
use App\Models\IngredientEnrichmentBatchItem;
use App\Models\User;
use App\Services\IngredientTranslationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class ApplyIngredientGuidanceRefresh
{
    public function __construct(
        private readonly IngredientEnrichmentSnapshotBuilder $snapshots,
        private readonly IngredientGuidanceRefreshResultValidator $validator,
        private readonly IngredientTranslationService $translations,
        private readonly IngredientEnrichmentBatchService $batches,
    ) {}

    /** @return array{applied:int,unchanged:int,stale:int,failed:int} */
    public function handle(User $actor, IngredientEnrichmentBatch $batch): array
    {
        $totals = ['applied' => 0, 'unchanged' => 0, 'stale' => 0, 'failed' => 0];
        $approvedItemIds = $batch->items()
            ->where('status', IngredientEnrichmentItemStatus::Approved->value)
            ->pluck('id');

        foreach ($approvedItemIds as $itemId) {
            try {
                $status = DB::transaction(function () use ($itemId): string {
                    $item = IngredientEnrichmentBatchItem::query()->lockForUpdate()->findOrFail($itemId);
                    $item->update(['status' => IngredientEnrichmentItemStatus::Applying]);
                    $batch = $item->batch()->firstOrFail();
                    $mode = $batch->mode;
                    if (! $mode instanceof IngredientEnrichmentBatchMode || ! $mode->isGuidance()) {
                        throw ValidationException::withMessages([
                            'batch' => __('ingredient_enrichment_admin.validation.guidance_batch_mode'),
                        ]);
                    }

                    $ingredient = Ingredient::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($item->ingredient_id);
                    $result = is_array($item->result) ? $item->result : [];
                    if ($this->snapshots->fingerprint($ingredient) !== (string) ($result['source_fingerprint'] ?? '')) {
                        $item->update(['status' => IngredientEnrichmentItemStatus::Stale]);

                        return 'stale';
                    }

                    $report = $this->validator->validateOrFail(
                        $result,
                        $ingredient,
                        $mode,
                        collect($result['translations'] ?? [])->pluck('locale')->filter()->values()->all(),
                    );
                    $normalized = $report['normalized'];
                    $changed = false;
                    if (! $mode->isLocalizationOnly()
                        && (string) ($ingredient->info_markdown ?? '') !== (string) ($normalized['info_markdown'] ?? '')) {
                        $ingredient->info_markdown = $normalized['info_markdown'];
                        $ingredient->save();
                        $changed = true;
                    }

                    $translationRows = $ingredient->translations()
                        ->get(['locale', 'display_name', 'saponification_name', 'info_markdown'])
                        ->map(fn ($translation): array => [
                            'locale' => $translation->locale,
                            'display_name' => $translation->display_name,
                            'saponification_name' => $translation->saponification_name,
                            'info_markdown' => $translation->info_markdown,
                        ])
                        ->keyBy('locale');
                    foreach ($normalized['translations'] ?? [] as $translation) {
                        if (! is_array($translation)) {
                            continue;
                        }
                        $locale = (string) ($translation['locale'] ?? '');
                        $current = $translationRows->get($locale, [
                            'locale' => $locale,
                            'display_name' => null,
                            'saponification_name' => null,
                            'info_markdown' => null,
                        ]);
                        if (($current['info_markdown'] ?? null) !== ($translation['info_markdown'] ?? null)) {
                            $changed = true;
                        }
                        $translationRows->put($locale, [
                            ...$current,
                            'info_markdown' => $translation['info_markdown'] ?? null,
                        ]);
                    }
                    $this->translations->sync(
                        $ingredient,
                        $translationRows->values()->all(),
                        IngredientTranslationOrigin::AiGenerated,
                        (string) ($normalized['prompt_versions']['localization'] ?? config('ingredient-enrichment.openai.guidance_localization_prompt_version')),
                    );

                    $sourceData = is_array($ingredient->source_data) ? $ingredient->source_data : [];
                    data_set($sourceData, 'enrichment.guidance', [
                        'evidence' => $normalized['guidance_evidence'] ?? [],
                        'guidance_prompt_version' => $normalized['prompt_versions']['guidance'] ?? config('ingredient-enrichment.openai.guidance_prompt_version'),
                        'localization_prompt_version' => $normalized['prompt_versions']['localization'] ?? config('ingredient-enrichment.openai.guidance_localization_prompt_version'),
                        'approved_at' => $item->approved_at?->toIso8601String() ?? CarbonImmutable::now()->toIso8601String(),
                    ]);
                    $ingredient->source_data = $sourceData;
                    $ingredient->save();

                    $item->update([
                        'status' => $changed ? IngredientEnrichmentItemStatus::Applied : IngredientEnrichmentItemStatus::Unchanged,
                        'result' => $normalized,
                        'validation_report' => $report,
                        'applied_at' => now(),
                    ]);

                    return $changed ? 'applied' : 'unchanged';
                }, attempts: 5);
                $totals[$status]++;
            } catch (ValidationException) {
                IngredientEnrichmentBatchItem::query()->whereKey($itemId)->update(['status' => IngredientEnrichmentItemStatus::Stale]);
                $totals['stale']++;
            } catch (Throwable $exception) {
                report($exception);
                IngredientEnrichmentBatchItem::query()->whereKey($itemId)->update([
                    'status' => IngredientEnrichmentItemStatus::Failed,
                    'failure_message' => __('ingredient_enrichment_admin.validation.apply_failed'),
                ]);
                $totals['failed']++;
            }
        }

        $this->batches->refresh($batch->id);

        return $totals;
    }
}
