<?php

namespace App\Models;

use Database\Factories\ProductionBatchPackagingItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'production_batch_id',
    'user_packaging_item_id',
    'position',
    'name',
    'components_per_unit',
    'unit_cost',
    'cost_per_finished_unit',
    'line_cost',
])]
class ProductionBatchPackagingItem extends Model
{
    /** @use HasFactory<ProductionBatchPackagingItemFactory> */
    use HasFactory;

    public function productionBatch(): BelongsTo
    {
        return $this->belongsTo(ProductionBatch::class);
    }

    public function packagingItem(): BelongsTo
    {
        return $this->belongsTo(UserPackagingItem::class, 'user_packaging_item_id');
    }

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'components_per_unit' => 'decimal:3',
            'unit_cost' => 'decimal:4',
            'cost_per_finished_unit' => 'decimal:4',
            'line_cost' => 'decimal:4',
        ];
    }
}
