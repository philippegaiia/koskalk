<?php

namespace App\Models;

use Database\Factories\WorkspaceMaterialSettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'ingredient_id',
    'packaging_item_id',
    'buffer_quantity',
])]
class WorkspaceMaterialSetting extends Model
{
    /** @use HasFactory<WorkspaceMaterialSettingFactory> */
    use HasFactory;

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class)->withoutGlobalScopes();
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class)->withoutGlobalScopes();
    }

    public function packagingItem(): BelongsTo
    {
        return $this->belongsTo(PackagingItem::class);
    }

    protected function casts(): array
    {
        return [
            'buffer_quantity' => 'decimal:9',
        ];
    }
}
