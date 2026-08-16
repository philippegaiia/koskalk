<?php

namespace App\Services;

use App\Enums\MediaAssetStatus;
use App\Enums\MediaAssetType;
use App\Enums\MediaAssetUsageRole;
use App\Models\MediaAsset;
use App\Models\MediaAssetUsage;
use App\Models\PackagingItem;
use App\Models\Recipe;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MediaAssetUsageService
{
    public function copyRecipeUsages(Recipe $source, Recipe $destination): void
    {
        $roles = [
            MediaAssetUsageRole::RecipeFeatured,
            MediaAssetUsageRole::RecipeSop,
            MediaAssetUsageRole::RecipeSopDocument,
        ];
        $sourceUsages = MediaAssetUsage::query()
            ->where('usable_type', $source->getMorphClass())
            ->where('usable_id', $source->getKey())
            ->whereIn('role', $roles)
            ->orderBy('id')
            ->get(['media_asset_id', 'role']);

        MediaAssetUsage::query()
            ->where('usable_type', $destination->getMorphClass())
            ->where('usable_id', $destination->getKey())
            ->whereIn('role', $roles)
            ->delete();

        foreach ($sourceUsages as $usage) {
            MediaAssetUsage::query()->create([
                'media_asset_id' => $usage->media_asset_id,
                'usable_type' => $destination->getMorphClass(),
                'usable_id' => $destination->getKey(),
                'role' => $usage->role,
            ]);
        }

        $destination->clearMediaAssetUsageCache();
    }

    public function syncSingle(
        User $user,
        Model $usable,
        MediaAssetUsageRole $role,
        int|string|null $mediaAssetId,
        MediaAssetType $expectedType = MediaAssetType::Image,
    ): void {
        $this->syncMany(
            $user,
            $usable,
            $role,
            filled($mediaAssetId) ? [(int) $mediaAssetId] : [],
            maximum: 1,
            expectedType: $expectedType,
        );
    }

    /**
     * @param  array<int, int|string>  $mediaAssetIds
     */
    public function syncMany(
        User $user,
        Model $usable,
        MediaAssetUsageRole $role,
        array $mediaAssetIds,
        int $maximum,
        MediaAssetType $expectedType = MediaAssetType::Image,
    ): void {
        $ids = collect($mediaAssetIds)
            ->map(fn (int|string $id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($ids->count() > $maximum) {
            throw ValidationException::withMessages([
                'media' => __(
                    $expectedType === MediaAssetType::Pdf
                        ? 'media_library.validation.maximum_documents'
                        : 'media_library.validation.maximum_images',
                    ['max' => $maximum],
                ),
            ]);
        }

        if ($ids->isEmpty()) {
            MediaAssetUsage::query()
                ->where('usable_type', $usable->getMorphClass())
                ->where('usable_id', $usable->getKey())
                ->where('role', $role)
                ->delete();

            if (method_exists($usable, 'clearMediaAssetUsageCache')) {
                $usable->clearMediaAssetUsageCache();
            }

            return;
        }

        $workspace = $this->workspaceFor($user, $usable);

        $assets = MediaAsset::query()
            ->where('workspace_id', $workspace->id)
            ->where('status', MediaAssetStatus::Ready)
            ->where('type', $expectedType)
            ->whereIn('id', $ids)
            ->get();

        if ($assets->count() !== $ids->count()) {
            throw ValidationException::withMessages([
                'media' => __('media_library.validation.selected_media_unavailable'),
            ]);
        }

        DB::transaction(function () use ($assets, $role, $usable): void {
            MediaAssetUsage::query()
                ->where('usable_type', $usable->getMorphClass())
                ->where('usable_id', $usable->getKey())
                ->where('role', $role)
                ->delete();

            foreach ($assets as $asset) {
                MediaAssetUsage::query()->create([
                    'media_asset_id' => $asset->id,
                    'usable_type' => $usable->getMorphClass(),
                    'usable_id' => $usable->getKey(),
                    'role' => $role,
                ]);
            }
        });

        if (method_exists($usable, 'clearMediaAssetUsageCache')) {
            $usable->clearMediaAssetUsageCache();
        }
    }

    /**
     * @return array<int, int>
     */
    public function idsFor(Model $usable, MediaAssetUsageRole $role): array
    {
        return MediaAssetUsage::query()
            ->where('usable_type', $usable->getMorphClass())
            ->where('usable_id', $usable->getKey())
            ->where('role', $role)
            ->orderBy('id')
            ->pluck('media_asset_id')
            ->all();
    }

    private function workspaceFor(User $user, Model $usable): Workspace
    {
        $workspaceId = $usable instanceof PackagingItem
            ? $user->company()?->id
            : $usable->getAttribute('workspace_id');
        $workspace = filled($workspaceId)
            ? Workspace::withoutGlobalScopes()->find((int) $workspaceId)
            : $user->company();

        if (! $workspace instanceof Workspace || ! $workspace->hasMember($user)) {
            throw ValidationException::withMessages([
                'media' => __('media_library.validation.workspace_unavailable'),
            ]);
        }

        return $workspace;
    }
}
