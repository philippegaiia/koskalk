<?php

namespace App\Livewire\Concerns;

use App\Forms\Components\MediaAssetPicker;
use App\Models\MediaAsset;
use App\Models\User;
use App\Services\MediaAssetUploadService;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

trait InteractsWithMediaAssetPickerUploads
{
    /**
     * @return array{asset_id?: int, status?: string, status_url?: string, error?: string}
     */
    public function startMediaAssetPickerUpload(string $statePath, MediaAssetUploadService $uploads): array
    {
        $picker = $this->mediaAssetPickerForStatePath($statePath);
        abort_unless($picker instanceof MediaAssetPicker, 404);

        $attachmentPath = "{$statePath}.mediaPickerUpload";
        $upload = data_get($this->componentFileAttachments, $attachmentPath);

        if (! $upload instanceof UploadedFile) {
            return ['error' => __('media_library.picker.upload_failed')];
        }

        $user = auth()->user();
        $workspace = $user instanceof User ? $user->company() : null;

        abort_unless($user instanceof User && $workspace !== null, 403);

        try {
            $asset = $uploads->start(
                $user,
                $workspace,
                $upload,
                $picker->getAcceptedMediaAssetTypes(),
            );
        } catch (ValidationException $exception) {
            return [
                'error' => Arr::first(Arr::flatten($exception->errors()))
                    ?? __('media_library.picker.upload_failed'),
            ];
        } finally {
            Arr::forget($this->componentFileAttachments, $attachmentPath);
        }

        return $this->mediaAssetPickerUploadResponse($asset);
    }

    private function mediaAssetPickerForStatePath(string $statePath): ?MediaAssetPicker
    {
        foreach ($this->getCachedSchemas() as $schema) {
            if (! $schema instanceof Schema) {
                continue;
            }

            foreach ($schema->getFlatComponents(withActions: false, withHidden: true) as $component) {
                if (
                    $component instanceof Component
                    && $component->getStatePath() === $statePath
                    && $component instanceof MediaAssetPicker
                ) {
                    return $component;
                }
            }
        }

        return null;
    }

    /**
     * @return array{asset_id: int, status: string, status_url: string}
     */
    private function mediaAssetPickerUploadResponse(MediaAsset $asset): array
    {
        return [
            'asset_id' => $asset->id,
            'status' => $asset->status->value,
            'status_url' => route('media.status', $asset),
        ];
    }
}
