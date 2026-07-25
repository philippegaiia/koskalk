<?php

namespace App\Services;

use App\Jobs\NormalizeMediaAssetJob;
use App\MediaAssetStatus;
use App\Models\MediaAsset;
use App\Models\User;
use App\Models\Workspace;
use App\WorkspaceMemberRole;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class MediaAssetUploadService
{
    public function __construct(
        private readonly EntitlementService $entitlements,
    ) {}

    public function start(User $user, Workspace $workspace, UploadedFile $upload): MediaAsset
    {
        Gate::forUser($user)->authorize('create', MediaAsset::class);
        $this->assertCanEditWorkspace($user, $workspace);
        $this->validateUpload($upload);

        $disk = (string) config('media.asset_pending_disk');
        $extension = strtolower($upload->getClientOriginalExtension());
        $pendingPath = 'media-assets/pending/'.$workspace->public_id.'/'.Str::uuid().'.'.$extension;

        $this->storePendingUpload($disk, $pendingPath, $upload);

        try {
            $asset = $this->entitlements->withinWorkspaceQuotaLock(
                $workspace,
                function (Workspace $lockedWorkspace) use ($disk, $pendingPath, $upload, $user): MediaAsset {
                    $this->assertCanEditWorkspace($user, $lockedWorkspace);
                    $this->entitlements->assertCanUploadMediaAssetInWorkspace($lockedWorkspace);

                    return MediaAsset::query()->create([
                        'workspace_id' => $lockedWorkspace->id,
                        'uploaded_by_user_id' => $user->id,
                        'status' => MediaAssetStatus::Processing,
                        'original_filename' => $upload->getClientOriginalName(),
                        'original_mime_type' => $upload->getMimeType(),
                        'original_size' => $upload->getSize(),
                        'pending_disk' => $disk,
                        'pending_path' => $pendingPath,
                        'progress' => 0,
                        'processing_stage' => 'queued',
                        'processing_token' => (string) Str::uuid(),
                    ]);
                },
            );
        } catch (Throwable $exception) {
            Storage::disk($disk)->delete($pendingPath);

            throw $exception;
        }

        $this->dispatchProcessing($asset);

        return $asset;
    }

    public function retry(User $user, MediaAsset $asset): MediaAsset
    {
        Gate::forUser($user)->authorize('update', $asset);

        $retried = $this->entitlements->withinWorkspaceQuotaLock(
            $asset->workspace,
            function (Workspace $lockedWorkspace) use ($asset, $user): MediaAsset {
                $lockedAsset = MediaAsset::query()->lockForUpdate()->findOrFail($asset->id);

                Gate::forUser($user)->authorize('update', $lockedAsset);

                if ($lockedAsset->status !== MediaAssetStatus::Failed) {
                    throw ValidationException::withMessages([
                        'asset' => __('media_library.validation.retry_failed_only'),
                    ]);
                }

                if (
                    blank($lockedAsset->pending_path)
                    || ! Storage::disk($lockedAsset->pending_disk)->exists($lockedAsset->pending_path)
                ) {
                    throw ValidationException::withMessages([
                        'asset' => __('media_library.validation.retry_source_missing'),
                    ]);
                }

                $this->entitlements->assertCanUploadMediaAssetInWorkspace($lockedWorkspace);

                $lockedAsset->update([
                    'status' => MediaAssetStatus::Processing,
                    'progress' => 0,
                    'processing_stage' => 'queued',
                    'failure_code' => null,
                    'failure_reason' => null,
                    'processing_token' => (string) Str::uuid(),
                ]);

                return $lockedAsset;
            },
        );

        $this->dispatchProcessing($retried);

        return $retried;
    }

    private function dispatchProcessing(MediaAsset $asset): void
    {
        NormalizeMediaAssetJob::dispatch($asset->id, $asset->processing_token)
            ->onQueue('media')
            ->afterCommit();
    }

    private function storePendingUpload(string $disk, string $pendingPath, UploadedFile $upload): void
    {
        try {
            $storedPath = Storage::disk($disk)->putFileAs(
                dirname($pendingPath),
                $upload,
                basename($pendingPath),
            );
        } catch (Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages([
                'upload' => __('media_library.validation.upload_store_failed'),
            ]);
        }

        if ($storedPath === false) {
            throw ValidationException::withMessages([
                'upload' => __('media_library.validation.upload_store_failed'),
            ]);
        }
    }

    private function validateUpload(UploadedFile $upload): void
    {
        $extension = strtolower($upload->getClientOriginalExtension());
        $acceptedExtensions = config('media.asset_uploads.accepted_extensions', []);
        $maxBytes = ((int) config('media.asset_uploads.max_size_kb', 10240)) * 1024;
        $mimeType = $upload->getMimeType();

        if (! in_array($extension, $acceptedExtensions, true)) {
            throw ValidationException::withMessages([
                'upload' => __('media_library.validation.upload_extension'),
            ]);
        }

        $detectedType = $this->detectedImageType($upload);
        $expectedType = match ($extension) {
            'jpg', 'jpeg' => 'jpeg',
            'png' => 'png',
            'webp' => 'webp',
            'heic', 'heif' => 'heif',
            default => null,
        };
        $acceptedMimeTypes = match ($expectedType) {
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
            'webp' => ['image/webp'],
            'heif' => ['image/heic', 'image/heif', 'image/heic-sequence', 'image/heif-sequence', 'application/octet-stream'],
            default => [],
        };

        if ($detectedType !== $expectedType || ! in_array($mimeType, $acceptedMimeTypes, true)) {
            throw ValidationException::withMessages([
                'upload' => __('media_library.validation.upload_invalid_image'),
            ]);
        }

        if (($upload->getSize() ?: 0) > $maxBytes) {
            throw ValidationException::withMessages([
                'upload' => __('media_library.validation.upload_size', [
                    'max' => max(1, (int) ceil($maxBytes / 1024 / 1024)),
                ]),
            ]);
        }
    }

    private function detectedImageType(UploadedFile $upload): ?string
    {
        $header = file_get_contents($upload->getPathname(), false, null, 0, 32);

        if (! is_string($header)) {
            return null;
        }

        if (str_starts_with($header, "\xFF\xD8\xFF") || str_starts_with($header, "\x89PNG\r\n\x1A\n")) {
            return str_starts_with($header, "\xFF\xD8\xFF") ? 'jpeg' : 'png';
        }

        if (strlen($header) >= 12 && substr($header, 0, 4) === 'RIFF' && substr($header, 8, 4) === 'WEBP') {
            return 'webp';
        }

        return strlen($header) >= 12
            && substr($header, 4, 4) === 'ftyp'
            && in_array(substr($header, 8, 4), ['heic', 'heix', 'hevc', 'hevx', 'heif', 'mif1', 'msf1'], true)
                ? 'heif'
                : null;
    }

    private function assertCanEditWorkspace(User $user, Workspace $workspace): void
    {
        $role = $workspace->roleFor($user);

        if (! in_array($role, [
            WorkspaceMemberRole::Owner,
            WorkspaceMemberRole::Admin,
            WorkspaceMemberRole::Editor,
        ], true)) {
            throw new AuthorizationException;
        }
    }
}
