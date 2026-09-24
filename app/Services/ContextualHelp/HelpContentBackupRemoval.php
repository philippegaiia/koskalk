<?php

namespace App\Services\ContextualHelp;

use App\Enums\HelpContentExportStatus;
use App\Models\HelpContentExport;
use App\Models\HelpTopic;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

final class HelpContentBackupRemoval
{
    public function remove(User $actor, string $publicId, bool $failedOnly = false): void
    {
        Gate::forUser($actor)->authorize('export', HelpTopic::class);

        DB::transaction(function () use ($publicId, $failedOnly): void {
            $export = HelpContentExport::query()->where('public_id', $publicId)->lockForUpdate()->firstOrFail();
            if ($export->removed_at !== null) {
                return;
            }
            $allowed = $failedOnly ? [HelpContentExportStatus::Failed] : [HelpContentExportStatus::Failed, HelpContentExportStatus::Succeeded];
            if (! in_array($export->status, $allowed, true)) {
                throw ValidationException::withMessages(['backupRemoval' => 'Only finished or failed backups can be deleted. Refresh the list and try again.']);
            }
            if ($export->path) {
                try {
                    $disk = Storage::disk($export->disk ?? config('contextual-help.disk'));
                    if ($disk->exists($export->path) && ! $disk->delete($export->path)) {
                        throw new RuntimeException('Storage refused backup deletion.');
                    }
                    if ($disk->exists($export->path)) {
                        throw new RuntimeException('Backup file still exists after deletion.');
                    }
                } catch (Throwable $exception) {
                    report($exception);
                    throw ValidationException::withMessages(['backupRemoval' => 'The backup file could not be deleted. The entry has been kept so you can try again.']);
                }
            }
            $export->update(['removed_at' => now()]);
        }, attempts: 5);
    }
}
