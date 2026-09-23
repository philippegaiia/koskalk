<?php

namespace App\Services;

use App\Enums\MediaAssetStatus;
use App\Enums\MediaAssetType;
use App\Jobs\RegenerateMediaAssetConversionsJob;
use App\Models\MediaAsset;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class MediaAssetLibraryService
{
    public function __construct(
        private readonly MediaAssetReferencePurger $referencePurger,
        private readonly MediaLabelService $labels,
    ) {}

    /**
     * @param  array<int, int|string>  $labelIds
     */
    public function saveSettings(User $user, MediaAsset $asset, string $displayName, array $labelIds, float $focalX, float $focalY): void
    {
        Gate::forUser($user)->authorize('update', $asset);

        DB::transaction(function () use ($user, $asset, $displayName, $labelIds, $focalX, $focalY): void {
            $lockedAsset = MediaAsset::query()->lockForUpdate()->findOrFail($asset->id);
            Gate::forUser($user)->authorize('update', $lockedAsset);
            abort_unless($lockedAsset->status === MediaAssetStatus::Ready, 409);

            $this->rename($user, $lockedAsset, $displayName);
            $this->labels->sync($user, $lockedAsset, $labelIds);

            if ($lockedAsset->type === MediaAssetType::Image
                && ! $lockedAsset->usesDocumentImageProfile()
                && ($lockedAsset->focal_x !== $focalX || $lockedAsset->focal_y !== $focalY)) {
                $this->updateFocalPoint($user, $lockedAsset, $focalX, $focalY);
            }
        }, attempts: 5);
    }

    public function rename(User $user, MediaAsset $asset, string $displayName): void
    {
        Gate::forUser($user)->authorize('update', $asset);

        $validated = Validator::validate(
            ['display_name' => $displayName],
            ['display_name' => ['required', 'string', 'max:255']],
            [
                'display_name.required' => __('media_library.validation.display_name_required'),
                'display_name.max' => __('media_library.validation.display_name_max'),
            ],
        );

        DB::transaction(function () use ($asset, $user, $validated): void {
            $lockedAsset = MediaAsset::query()
                ->lockForUpdate()
                ->findOrFail($asset->id);

            Gate::forUser($user)->authorize('update', $lockedAsset);

            $lockedAsset->update(['display_name' => $validated['display_name']]);
            $lockedAsset->getFirstMedia('master')?->update(['name' => $validated['display_name']]);
            $lockedAsset->getFirstMedia('document')?->update(['name' => $validated['display_name']]);
        });
    }

    public function remove(User $user, MediaAsset $asset): void
    {
        Gate::forUser($user)->authorize('delete', $asset);

        DB::transaction(function () use ($asset, $user): void {
            $lockedAsset = MediaAsset::query()
                ->lockForUpdate()
                ->findOrFail($asset->id);

            Gate::forUser($user)->authorize('delete', $lockedAsset);

            if ($lockedAsset->isReferencedExternally()) {
                throw ValidationException::withMessages([
                    'media_asset' => __('media_library.validation.asset_in_use_by_documents'),
                ]);
            }

            $this->referencePurger->purge($lockedAsset);

            if (filled($lockedAsset->pending_disk) && filled($lockedAsset->pending_path)) {
                Storage::disk($lockedAsset->pending_disk)->delete($lockedAsset->pending_path);
            }

            $lockedAsset->delete();
        });
    }

    public function updateFocalPoint(
        User $user,
        MediaAsset $asset,
        float $focalX,
        float $focalY,
    ): void {
        Gate::forUser($user)->authorize('update', $asset);

        if ($focalX < 0 || $focalX > 100 || $focalY < 0 || $focalY > 100) {
            throw ValidationException::withMessages([
                'focal_point' => __('media_library.validation.focal_point'),
            ]);
        }

        $asset->update([
            'focal_x' => $focalX,
            'focal_y' => $focalY,
        ]);

        RegenerateMediaAssetConversionsJob::dispatch($asset->id)
            ->onQueue('media')
            ->afterCommit();
    }
}
