<?php

namespace App\Jobs;

use App\Services\IngredientEnrichment\ResearchIngredientEnrichmentItem;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class ResearchIngredientEnrichment implements ShouldBeUnique, ShouldQueue
{
    use Batchable;
    use Queueable;

    public int $tries = 3;

    public int $timeout = 330;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly int $itemId,
        public readonly bool $allowGapResearch = false,
    ) {}

    public function handle(ResearchIngredientEnrichmentItem $processor): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $processor->handle($this->itemId, $this->allowGapResearch);
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception !== null) {
            app(ResearchIngredientEnrichmentItem::class)->markFailed($this->itemId, $exception);
        }
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("ingredient-enrichment:{$this->itemId}"))->expireAfter($this->timeout + 30)];
    }

    public function uniqueId(): string
    {
        return (string) $this->itemId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 300];
    }
}
