<?php

namespace App\Services\IngredientEnrichment;

use App\Data\IngredientSourceStageResult;
use App\Enums\IngredientEnrichmentResearchStage;
use App\Models\IngredientEnrichmentBatchItem;
use Illuminate\Support\Facades\DB;
use Throwable;

class IngredientGuidanceStageRunner
{
    public function __construct(
        private readonly IngredientEnrichmentStageStore $stages,
    ) {}

    /**
     * @param  callable(): IngredientSourceStageResult  $callback
     */
    public function run(
        int $itemId,
        IngredientEnrichmentResearchStage $stage,
        callable $callback,
    ): IngredientSourceStageResult {
        try {
            $stored = DB::transaction(function () use ($itemId, $stage): ?IngredientSourceStageResult {
                $item = IngredientEnrichmentBatchItem::query()
                    ->lockForUpdate()
                    ->findOrFail($itemId);
                $stored = $this->stages->stages($item)[$stage->value] ?? null;

                if (! is_array($stored) || ($stored['status'] ?? null) !== 'completed') {
                    return null;
                }

                $storedResult = IngredientSourceStageResult::fromArray($stored);
                if ($storedResult->stage !== $stage) {
                    throw new \LogicException("Unexpected enrichment stage {$storedResult->stage->value}.");
                }

                return $storedResult;
            }, attempts: 5);

            if ($stored instanceof IngredientSourceStageResult) {
                return $stored;
            }

            $result = $callback();
            if (! $result instanceof IngredientSourceStageResult) {
                throw new \LogicException("Unexpected result for enrichment stage {$stage->value}.");
            }
            if ($result->stage !== $stage) {
                throw new \LogicException("Unexpected enrichment stage {$result->stage->value}.");
            }
            if ($result->status !== 'completed') {
                throw new \LogicException("Enrichment stage {$stage->value} did not complete.");
            }

            $this->stages->complete($itemId, $result);

            return $result;
        } catch (Throwable $exception) {
            $this->stages->fail($itemId, $stage, $this->safeFailureCode($exception));

            throw $exception;
        }
    }

    private function safeFailureCode(Throwable $exception): string
    {
        if ($exception instanceof IngredientResearchProviderException) {
            return $exception->failureCode;
        }

        return mb_strtolower(class_basename($exception));
    }
}
