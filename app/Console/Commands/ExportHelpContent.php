<?php

namespace App\Console\Commands;

use App\Enums\HelpContentExportReason;
use App\Enums\HelpContentExportStatus;
use App\Services\ContextualHelp\HelpContentExportService;
use App\Services\ContextualHelp\HelpContentManifest;
use App\Services\ContextualHelp\HelpContentSnapshot;
use Illuminate\Console\Command;
use Throwable;

class ExportHelpContent extends Command
{
    protected $signature = 'help:export {--disk=} {--output=}';

    protected $description = 'Export portable contextual help to a new local JSON file or a verified private disk snapshot';

    public function handle(HelpContentSnapshot $snapshot, HelpContentManifest $manifests, HelpContentExportService $exports): int
    {
        try {
            if ($path = $this->option('output')) {
                $manifest = $manifests->validate($snapshot->capture());
                $handle = @fopen($path, 'x');
                if ($handle === false) {
                    $this->error('Output must be a new writable file.');

                    return self::FAILURE;
                }
                try {
                    chmod($path, 0600);
                    $json = $manifests->encode($manifest);
                    if (fwrite($handle, $json) !== strlen($json)) {
                        throw new \RuntimeException('Could not write complete manifest.');
                    }
                } finally {
                    fclose($handle);
                }
                $this->info($path);
            } else {
                $export = $exports->request(HelpContentExportReason::Manual, dispatch: false);
                if ($disk = $this->option('disk')) {
                    $export->update(['disk' => $disk]);
                }
                $export = $exports->run($export);
                if ($export->status !== HelpContentExportStatus::Succeeded) {
                    $this->error('Snapshot did not complete successfully.');

                    return self::FAILURE;
                }
                $this->info($export->disk.':'.$export->path);
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
