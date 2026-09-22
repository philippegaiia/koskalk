<?php

namespace App\Jobs;

use App\Models\HelpTranslationRequest;
use App\Services\ContextualHelp\HelpTranslationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class TranslateHelpTopic implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(public int $requestId)
    {
        $this->onConnection('database');
        $this->onQueue(config('contextual-help.queue', config('ingredient-enrichment.direct_ai.queue', 'enrichment')));
        $this->afterCommit();
    }

    public function handle(HelpTranslationService $service): void
    {
        $request = HelpTranslationRequest::query()->find($this->requestId);
        if ($request) {
            $service->execute($request);
        }
    }
}
