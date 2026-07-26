<?php

namespace App\Models;

use Database\Factories\MediaLabelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['workspace_id', 'created_by_user_id', 'name', 'normalized_name'])]
class MediaLabel extends Model
{
    /** @use HasFactory<MediaLabelFactory> */
    use HasFactory;

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class)->withoutGlobalScopes();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function mediaAssets(): BelongsToMany
    {
        return $this->belongsToMany(MediaAsset::class, 'media_asset_label')->withTimestamps();
    }
}
