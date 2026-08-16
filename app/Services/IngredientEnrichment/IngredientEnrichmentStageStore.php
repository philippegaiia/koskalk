<?php

namespace App\Services\IngredientEnrichment;

use App\Data\IngredientSourceStageResult;
use App\Enums\IngredientEnrichmentResearchStage;
use App\Models\IngredientEnrichmentBatchItem;
use Illuminate\Support\Facades\DB;

class IngredientEnrichmentStageStore
{
    public function complete(int $itemId, IngredientSourceStageResult $result): IngredientEnrichmentBatchItem
    {
        return DB::transaction(function () use ($itemId, $result): IngredientEnrichmentBatchItem {
            $item = IngredientEnrichmentBatchItem::query()->lockForUpdate()->findOrFail($itemId);
            $stages = $this->stages($item);
            $stages[$result->stage->value] = $result->toArray();
            $item->update(['research_stages' => $this->ordered($stages)]);

            return $item->refresh();
        }, attempts: 5);
    }

    public function fail(
        int $itemId,
        IngredientEnrichmentResearchStage $stage,
        string $safeCode,
    ): IngredientEnrichmentBatchItem {
        return DB::transaction(function () use ($itemId, $stage, $safeCode): IngredientEnrichmentBatchItem {
            $item = IngredientEnrichmentBatchItem::query()->lockForUpdate()->findOrFail($itemId);
            $stages = $this->stages($item);
            $stages[$stage->value] = [
                'stage' => $stage->value,
                'status' => 'failed',
                'data' => [],
                'evidence' => [],
                'warnings' => [],
                'unresolved_questions' => [],
                'source_calls' => 0,
                'failure_code' => $safeCode,
            ];
            $item->update(['research_stages' => $this->ordered($stages)]);

            return $item->refresh();
        }, attempts: 5);
    }

    public function invalidateFrom(int $itemId, IngredientEnrichmentResearchStage $stage): IngredientEnrichmentBatchItem
    {
        return DB::transaction(function () use ($itemId, $stage): IngredientEnrichmentBatchItem {
            $item = IngredientEnrichmentBatchItem::query()->lockForUpdate()->findOrFail($itemId);
            $invalidated = collect([$stage, ...$stage->downstream()])
                ->map(fn (IngredientEnrichmentResearchStage $invalidatedStage): string => $invalidatedStage->value)
                ->all();
            $stages = collect($this->stages($item))
                ->reject(fn (mixed $value, string $key): bool => in_array($key, $invalidated, true))
                ->all();
            $item->update(['research_stages' => $this->ordered($stages)]);

            return $item->refresh();
        }, attempts: 5);
    }

    /** @return array<string, array<string, mixed>> */
    public function stages(IngredientEnrichmentBatchItem $item): array
    {
        return collect($item->research_stages ?? [])
            ->filter(fn (mixed $stage, mixed $key): bool => is_string($key) && is_array($stage))
            ->all();
    }

    /**
     * @param  array<string, array<string, mixed>>  $stages
     * @return array<string, array<string, mixed>>
     */
    private function ordered(array $stages): array
    {
        return collect(IngredientEnrichmentResearchStage::ordered())
            ->mapWithKeys(function (IngredientEnrichmentResearchStage $stage) use ($stages): array {
                $value = $stages[$stage->value] ?? null;

                return is_array($value) ? [$stage->value => $value] : [];
            })
            ->all();
    }
}
