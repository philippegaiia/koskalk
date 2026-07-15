<?php

namespace App\Console\Commands;

use App\Services\PostgreSqlBackupService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('backup:database')]
#[Description('Create and upload a verified PostgreSQL database backup')]
class BackupDatabase extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(PostgreSqlBackupService $backupService): int
    {
        try {
            $backup = $backupService->create();
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Database backup failed. Review the application log for details.');

            return self::FAILURE;
        }

        $this->info("Database backup uploaded: {$backup['object_key']} ({$backup['size']} bytes)");

        return self::SUCCESS;
    }
}
