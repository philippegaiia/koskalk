<?php

namespace App\Console\Commands;

use App\Enums\HelpContentExportStatus;
use App\Services\ContextualHelp\HelpContentExportService;
use Illuminate\Console\Command;
use Throwable;

class SnapshotHelpContent extends Command
{
    protected $signature = 'help:snapshot';

    protected $description = 'Create and verify a private contextual help backup';

    public function handle(HelpContentExportService $exports): int
    {
        try {
            $export = $exports->scheduled();
            if ($export === null) {
                $this->info('Help content is unchanged; no new backup needed.');

                return self::SUCCESS;
            }
            if ($export->status !== HelpContentExportStatus::Succeeded) {
                $this->error('Snapshot did not complete successfully.');

                return self::FAILURE;
            }
            $this->info('Verified snapshot '.$export->public_id);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Help snapshot failed: '.$exception->getMessage());

            return self::FAILURE;
        }
    }
}
