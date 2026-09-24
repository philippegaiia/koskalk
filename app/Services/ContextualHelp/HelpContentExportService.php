<?php

namespace App\Services\ContextualHelp;

use App\Enums\HelpContentExportReason;
use App\Enums\HelpContentExportStatus;
use App\Jobs\ExportHelpContent;
use App\Models\HelpContentExport;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class HelpContentExportService
{
    public function __construct(private readonly HelpContentSnapshot $snapshot, private readonly HelpContentManifest $manifest) {}

    public function request(HelpContentExportReason $reason, ?User $actor = null, bool $dispatch = true): HelpContentExport
    {
        $export = HelpContentExport::query()->create(['reason' => $reason, 'status' => HelpContentExportStatus::Pending, 'requested_by' => $actor?->id, 'requires_admin_authorization' => $actor !== null, 'disk' => config('contextual-help.disk')]);
        if ($dispatch) {
            $this->dispatch($export);
        }

        return $export;
    }

    public function requestPublication(int $actorId): void
    {
        DB::afterCommit(function () use ($actorId): void {
            try {
                cache()->lock('help-publication-backup-request', 30)->block(5, function () use ($actorId): void {
                    DB::transaction(function () use ($actorId): void {
                        $pending = HelpContentExport::query()->whereNull('removed_at')
                            ->where('reason', HelpContentExportReason::Publication)
                            ->where('status', HelpContentExportStatus::Pending)->lockForUpdate()->first();
                        if ($pending) {
                            return;
                        }
                        $export = HelpContentExport::query()->create([
                            'reason' => HelpContentExportReason::Publication,
                            'status' => HelpContentExportStatus::Pending,
                            'requested_by' => $actorId,
                            'disk' => config('contextual-help.disk'),
                            'format_version' => 1,
                        ]);
                        $this->dispatch($export);
                    }, attempts: 5);
                });
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }

    public function scheduled(): ?HelpContentExport
    {
        $previous = HelpContentExport::query()->whereNull('removed_at')
            ->where('status', HelpContentExportStatus::Succeeded)->latest('completed_at')->latest('id')->first();
        if ($previous?->disk && $previous->path && $previous->checksum) {
            $storage = Storage::disk($previous->disk);
            if ($storage->exists($previous->path)) {
                $json = $storage->get($previous->path);
                if (hash_equals($previous->checksum, hash('sha256', $json))) {
                    $saved = $this->manifest->decode($json);
                    $current = $this->snapshot->capture();
                    if ($this->manifest->hash(['topics' => $saved['topics']]) === $this->manifest->hash(['topics' => $current['topics']])) {
                        return null;
                    }
                }
            }
        }

        return $this->run($this->request(HelpContentExportReason::Scheduled, dispatch: false));
    }

    public function dispatch(HelpContentExport $export): void
    {
        DB::afterCommit(function () use ($export): void {
            try {
                $job = ExportHelpContent::dispatch($export->id);
                if ($export->reason === HelpContentExportReason::Publication) {
                    $job->delay($export->created_at->addMinutes(2));
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }

    public function run(HelpContentExport $export): HelpContentExport
    {
        $token = (string) Str::uuid();
        $claimed = HelpContentExport::query()->whereKey($export->id)->whereNull('removed_at')->whereIn('status', [HelpContentExportStatus::Pending->value, HelpContentExportStatus::Failed->value])->update([
            'status' => HelpContentExportStatus::Running, 'processing_token' => $token, 'started_at' => now(), 'completed_at' => null, 'error_code' => null, 'error_message' => null,
        ]);
        if (! $claimed) {
            return $export->refresh();
        }
        try {
            if ($export->requires_admin_authorization && ! User::query()->whereKey($export->requested_by)->where('is_admin', true)->exists()) {
                throw new RuntimeException('The requesting administrator no longer has access.');
            }
            $data = $this->snapshot->capture();
            $data['export_uuid'] = $export->public_id;
            $data = $this->manifest->seal($data);
            $this->manifest->validate($data);
            $json = $this->manifest->encode($data);
            $checksum = hash('sha256', $json);
            $disk = $export->disk ?? config('contextual-help.disk');
            $path = 'help-content/'.now()->format('Y/m/d').'/'.$export->public_id.'/'.$token.'.json';
            $storage = Storage::disk($disk);
            if ($storage->exists($path) || ! $storage->put($path, $json, ['visibility' => 'private'])) {
                throw new RuntimeException('Snapshot could not be written.');
            }
            if ($storage->size($path) !== strlen($json) || ! hash_equals($checksum, hash('sha256', $storage->get($path)))) {
                throw new RuntimeException('Snapshot verification failed.');
            }
            HelpContentExport::query()->whereKey($export->id)->where('status', HelpContentExportStatus::Running->value)->where('processing_token', $token)->update([
                'status' => HelpContentExportStatus::Succeeded, 'disk' => $disk, 'path' => $path, 'format_version' => 1,
                'checksum' => $checksum, 'size_bytes' => strlen($json), 'captured_at' => $data['captured_at'], 'completed_at' => now(),
            ]);
        } catch (Throwable $exception) {
            HelpContentExport::query()->whereKey($export->id)->where('status', HelpContentExportStatus::Running->value)->where('processing_token', $token)->update([
                'status' => HelpContentExportStatus::Failed, 'error_code' => 'snapshot_failed', 'error_message' => 'Snapshot creation or remote verification failed.', 'completed_at' => now(),
            ]);
            throw $exception;
        }

        return $export->refresh();
    }

    public function beforeImport(): HelpContentExport
    {
        $export = $this->run($this->request(HelpContentExportReason::PreImport, dispatch: false));
        if ($export->status !== HelpContentExportStatus::Succeeded) {
            throw new RuntimeException('A verified pre-import snapshot is required.');
        }

        return $export;
    }
}
