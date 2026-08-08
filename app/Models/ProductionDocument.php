<?php

namespace App\Models;

use App\Enums\ProductionDocumentType;
use Database\Factories\ProductionDocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable([
    'workspace_id',
    'media_asset_id',
    'documentable_type',
    'documentable_id',
    'type',
    'attached_by_user_id',
    'note',
])]
class ProductionDocument extends Model
{
    /** @use HasFactory<ProductionDocumentFactory> */
    use HasFactory;

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class)->withoutGlobalScopes();
    }

    public function mediaAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class);
    }

    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function attachedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'attached_by_user_id');
    }

    protected function casts(): array
    {
        return [
            'type' => ProductionDocumentType::class,
        ];
    }
}
