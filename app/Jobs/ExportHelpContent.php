<?php

namespace App\Jobs;

use App\Models\HelpContentExport;
use App\Services\ContextualHelp\HelpContentExportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ExportHelpContent implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 3;

    public function __construct(public int $exportId)
    {
        $this->onConnection('database');
        $this->onQueue(config('contextual-help.queue', config('ingredient-enrichment.direct_ai.queue', 'enrichment')));
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60, 180];
    }

    public function handle(HelpContentExportService $service): void
    {
        $export = HelpContentExport::query()->find($this->exportId);
        if ($export) {
            $service->run($export);
        }
    }
}
