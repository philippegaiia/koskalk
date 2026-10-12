<?php

namespace App\Console\Commands;

use App\Jobs\GenerateFileExport;
use App\Models\FileExport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PruneFileExports extends Command
{
    protected $signature = 'exports:prune';

    protected $description = 'Recover interrupted exports and remove expired or orphaned private files';

    public function handle(): int
    {
        $staleBefore = now()->subSeconds(GenerateFileExport::TIMEOUT_SECONDS + 60);
        FileExport::query()->where('status', 'processing')->where('updated_at', '<', $staleBefore)
            ->each(function (FileExport $export) use ($staleBefore): void {
                if (! FileExport::query()->whereKey($export->id)->where('status', 'processing')
                    ->where('updated_at', '<', $staleBefore)->update([
                        'status' => 'failed',
                        'error' => trans('ingredients.exports.ui.background_failed', [], $export->locale),
                    ])) {
                    return;
                }
                Storage::disk($export->disk)->deleteDirectory('exports/'.$export->public_id);
                Storage::disk('local')->deleteDirectory('export-tmp/'.$export->public_id);
            });
        $this->call('model:prune', ['--model' => [FileExport::class]]);
        $disks = FileExport::query()->distinct()->pluck('disk')->push(config('exports.disk', 'local'))->unique();
        foreach ($disks as $diskName) {
            $disk = Storage::disk($diskName);
            foreach ($disk->directories('exports') as $directory) {
                if (! FileExport::query()->where('public_id', basename($directory))->where('disk', $diskName)->exists()) {
                    $disk->deleteDirectory($directory);
                }
            }
        }
        foreach (Storage::disk('local')->directories('export-tmp') as $directory) {
            if (! FileExport::query()->where('public_id', basename($directory))->exists()) {
                Storage::disk('local')->deleteDirectory($directory);
            }
        }

        return self::SUCCESS;
    }
}
