<?php

namespace App\Jobs;

use App\Services\IngredientEnrichment\IngredientGuidanceRefreshProcessor;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class GenerateIngredientGuidanceRefresh implements ShouldBeUnique, ShouldQueue
{
    use Batchable;
    use Queueable;

    public int $tries = 3;

    public int $timeout;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly int $itemId,
    ) {
        $this->timeout = (int) config('ingredient-enrichment.direct_ai.job_timeout_seconds');
    }

    public function handle(IngredientGuidanceRefreshProcessor $processor): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $processor->handle($this->itemId);
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception !== null) {
            app(IngredientGuidanceRefreshProcessor::class)->markFailed($this->itemId, $exception);
        }
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("ingredient-guidance-refresh:{$this->itemId}"))->expireAfter($this->timeout + 30)];
    }

    public function uniqueId(): string
    {
        return 'ingredient-guidance-refresh:'.$this->itemId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 300];
    }
}
