<?php

namespace App\Models;

use App\Enums\MaterialPriceSource;
use Database\Factories\CurrentMaterialPriceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'ingredient_id',
    'packaging_item_id',
    'price_per_canonical_unit',
    'currency',
    'recorded_at',
    'source_type',
    'source_id',
    'created_by_user_id',
])]
class CurrentMaterialPrice extends Model
{
    /** @use HasFactory<CurrentMaterialPriceFactory> */
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

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    protected function casts(): array
    {
        return [
            'price_per_canonical_unit' => 'decimal:12',
            'recorded_at' => 'datetime',
            'source_type' => MaterialPriceSource::class,
        ];
    }
}
