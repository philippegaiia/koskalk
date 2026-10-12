<?php

namespace App\Jobs;

use App\Models\FileExport;
use App\Models\User;
use App\Services\QueuedFileExports;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class GenerateFileExport implements ShouldQueue
{
    use Queueable;

    public const TIMEOUT_SECONDS = 120;

    public int $timeout = self::TIMEOUT_SECONDS;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(public int $exportId)
    {
        $this->onConnection(config('exports.connection', 'database'));
        $this->onQueue(config('exports.queue', 'exports'));
    }

    public function handle(QueuedFileExports $exports): void
    {
        $export = FileExport::query()->find($this->exportId);
        if (! $export || $export->expires_at->isPast()
            || ! FileExport::query()->whereKey($export->id)->where('status', 'pending')->update(['status' => 'processing'])) {
            return;
        }

        $locale = app()->getLocale();
        $previousUser = auth()->user();
        $temporaryPath = null;
        $temporaryDirectory = 'export-tmp/'.$export->public_id;
        $storagePath = 'exports/'.$export->public_id.'/download.'.$export->parameters['format'];
        try {
            app()->setLocale($export->locale);
            $actor = User::query()->findOrFail($export->user_id);
            auth()->setUser($actor);
            Storage::disk('local')->makeDirectory($temporaryDirectory);
            $file = $exports->build($export, $actor, Storage::disk('local')->path($temporaryDirectory));
            $temporaryPath = $file['path'];
            if (filesize($temporaryPath) > (int) config('exports.maximum_bytes', 25 * 1024 * 1024)) {
                throw ValidationException::withMessages(['export' => __('ingredients.exports.ui.too_large')]);
            }
            $stream = fopen($temporaryPath, 'rb');
            if ($stream === false) {
                throw new RuntimeException('Unable to open the generated export.');
            }
            try {
                if (! Storage::disk($export->disk)->put($storagePath, $stream, ['visibility' => 'private'])) {
                    throw new RuntimeException('Unable to store the generated export.');
                }
            } finally {
                fclose($stream);
            }
            $exports->authorize($actor->fresh(), $export);
            $export->update(['status' => 'ready', 'path' => $storagePath, 'filename' => $file['filename']]);
        } catch (Throwable $exception) {
            Storage::disk($export->disk)->deleteDirectory('exports/'.$export->public_id);
            $export->update([
                'status' => 'failed',
                'error' => $exception instanceof ValidationException
                    ? collect($exception->errors())->flatten()->first()
                    : __('ingredients.exports.ui.background_failed'),
            ]);
            if (! $exception instanceof ValidationException) {
                throw $exception;
            }
        } finally {
            if (is_string($temporaryPath) && is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
            app()->setLocale($locale);
            if ($previousUser) {
                auth()->setUser($previousUser);
            } else {
                auth()->forgetUser();
            }
            Storage::disk('local')->deleteDirectory($temporaryDirectory);
        }

        if ($export->status === 'ready') {
            $exports->trim($export);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $export = FileExport::query()->find($this->exportId);
        if ($export && in_array($export->status, ['pending', 'processing'], true)) {
            Storage::disk($export->disk)->deleteDirectory('exports/'.$export->public_id);
            Storage::disk('local')->deleteDirectory('export-tmp/'.$export->public_id);
            $export->update(['status' => 'failed', 'error' => trans('ingredients.exports.ui.background_failed', [], $export->locale)]);
        }
    }
}
