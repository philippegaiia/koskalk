<?php

namespace App\Models;

use App\MediaAssetUsageRole;
use Database\Factories\MediaAssetUsageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['media_asset_id', 'usable_type', 'usable_id', 'role'])]
class MediaAssetUsage extends Model
{
    /** @use HasFactory<MediaAssetUsageFactory> */
    use HasFactory;

    public function mediaAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class);
    }

    public function usable(): MorphTo
    {
        return $this->morphTo()->withoutGlobalScopes();
    }

    protected function casts(): array
    {
        return [
            'role' => MediaAssetUsageRole::class,
        ];
    }
}
