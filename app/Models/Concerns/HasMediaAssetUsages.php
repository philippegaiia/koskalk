<?php

namespace App\Models\Concerns;

use App\MediaAssetStatus;
use App\MediaAssetUsageRole;
use App\Models\MediaAsset;
use App\Models\MediaAssetUsage;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Collection;

trait HasMediaAssetUsages
{
    /** @var array<string, MediaAsset|null> */
    protected array $resolvedMediaAssetsByRole = [];

    public static function bootHasMediaAssetUsages(): void
    {
        static::deleting(function (self $model): void {
            $model->mediaAssetUsages()->delete();
        });
    }

    public function mediaAssetUsages(): MorphMany
    {
        return $this->morphMany(MediaAssetUsage::class, 'usable');
    }

    public function clearMediaAssetUsageCache(): void
    {
        $this->resolvedMediaAssetsByRole = [];
        $this->unsetRelation('mediaAssetUsages');
    }

    public function mediaAssetForRole(MediaAssetUsageRole $role): ?MediaAsset
    {
        if (array_key_exists($role->value, $this->resolvedMediaAssetsByRole)) {
            return $this->resolvedMediaAssetsByRole[$role->value];
        }

        if ($this->relationLoaded('mediaAssetUsages')) {
            return $this->resolvedMediaAssetsByRole[$role->value] = $this->mediaAssetUsages
                ->where('role', $role)
                ->map(fn (MediaAssetUsage $usage): ?MediaAsset => $usage->loadMissing('mediaAsset')->mediaAsset)
                ->first(fn (?MediaAsset $asset): bool => $asset?->status === MediaAssetStatus::Ready);
        }

        return $this->resolvedMediaAssetsByRole[$role->value] = $this->mediaAssetUsages()
            ->where('role', $role)
            ->whereHas('mediaAsset', fn ($query) => $query->where('status', MediaAssetStatus::Ready))
            ->with('mediaAsset')
            ->first()
            ?->mediaAsset;
    }

    /**
     * @return Collection<int, MediaAsset>
     */
    public function mediaAssetsForRole(MediaAssetUsageRole $role): Collection
    {
        if ($this->relationLoaded('mediaAssetUsages')) {
            return $this->mediaAssetUsages
                ->where('role', $role)
                ->sortBy('id')
                ->map(fn (MediaAssetUsage $usage): ?MediaAsset => $usage->loadMissing('mediaAsset')->mediaAsset)
                ->filter(fn (?MediaAsset $asset): bool => $asset?->status === MediaAssetStatus::Ready)
                ->values();
        }

        return $this->mediaAssetUsages()
            ->where('role', $role)
            ->whereHas('mediaAsset', fn ($query) => $query->where('status', MediaAssetStatus::Ready))
            ->with('mediaAsset')
            ->orderBy('id')
            ->get()
            ->pluck('mediaAsset')
            ->filter(fn (mixed $asset): bool => $asset instanceof MediaAsset)
            ->values();
    }
}
