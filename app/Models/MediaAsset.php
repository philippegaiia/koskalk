<?php

namespace App\Models;

use App\Enums\MediaAssetStatus;
use App\Enums\MediaAssetType;
use App\Models\Concerns\HasPublicId;
use Database\Factories\MediaAssetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

#[Fillable([
    'workspace_id',
    'uploaded_by_user_id',
    'status',
    'type',
    'original_filename',
    'display_name',
    'original_mime_type',
    'original_size',
    'width',
    'height',
    'pending_disk',
    'pending_path',
    'focal_x',
    'focal_y',
    'progress',
    'processing_stage',
    'failure_code',
    'failure_reason',
    'processing_token',
])]
class MediaAsset extends Model implements HasMedia
{
    /** @use HasFactory<MediaAssetFactory> */
    use HasFactory;

    use HasPublicId;
    use InteractsWithMedia;

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class)->withoutGlobalScopes();
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function usages(): HasMany
    {
        return $this->hasMany(MediaAssetUsage::class);
    }

    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(MediaLabel::class, 'media_asset_label')->withTimestamps();
    }

    public function displayName(): string
    {
        return $this->display_name ?: $this->original_filename;
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('master')
            ->useDisk(config('media.asset_disk'))
            ->acceptsMimeTypes(['image/webp'])
            ->singleFile();

        $this->addMediaCollection('document')
            ->useDisk(config('media.asset_disk'))
            ->acceptsMimeTypes(['application/pdf'])
            ->singleFile();
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $quality = (int) config('media.asset_uploads.quality', 85);

        $this->addMediaConversion('recipe-index')
            ->fit(Fit::Max, 640, 480)
            ->format('webp')
            ->quality($quality)
            ->performOnCollections('master')
            ->nonQueued();

        foreach ([
            'catalog' => 400,
            'thumbnail' => 240,
            'icon' => 96,
        ] as $name => $size) {
            $focalX = (int) round(($this->focal_x / 100) * ($this->width ?? $size));
            $focalY = (int) round(($this->focal_y / 100) * ($this->height ?? $size));

            $this->addMediaConversion($name)
                ->focalCropAndResize($size, $size, $focalX, $focalY)
                ->format('webp')
                ->quality($quality)
                ->performOnCollections('master')
                ->nonQueued();
        }
    }

    protected function casts(): array
    {
        return [
            'status' => MediaAssetStatus::class,
            'type' => MediaAssetType::class,
            'original_size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'focal_x' => 'float',
            'focal_y' => 'float',
            'progress' => 'integer',
        ];
    }
}
