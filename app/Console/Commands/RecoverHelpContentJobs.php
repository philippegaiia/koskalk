<?php

namespace App\Console\Commands;

use App\Enums\HelpContentExportStatus;
use App\Models\HelpContentExport;
use App\Services\ContextualHelp\HelpContentExportService;
use App\Services\ContextualHelp\HelpTranslationService;
use Illuminate\Console\Command;

class RecoverHelpContentJobs extends Command
{
    protected $signature = 'help:recover-jobs';

    protected $description = 'Redispatch pending help jobs and expire abandoned workers without retrying paid calls';

    public function handle(HelpTranslationService $translations, HelpContentExportService $exports): int
    {
        $translations->recover();
        HelpContentExport::query()->where('status', HelpContentExportStatus::Running)->where('started_at', '<', now()->subMinutes(10))->eachById(function (HelpContentExport $export) use ($exports): void {
            $expired = HelpContentExport::query()->whereKey($export->id)->where('status', HelpContentExportStatus::Running)->where('processing_token', $export->processing_token)->where('started_at', '<', now()->subMinutes(10))
                ->update(['status' => HelpContentExportStatus::Failed, 'processing_token' => null, 'completed_at' => now(), 'error_code' => 'worker_expired', 'error_message' => 'The snapshot worker expired.']);
            if ($expired) {
                $exports->dispatch($export);
            }
        });
        HelpContentExport::query()->where('status', HelpContentExportStatus::Pending)->eachById(fn (HelpContentExport $export) => $exports->dispatch($export));

        return self::SUCCESS;
    }
}
