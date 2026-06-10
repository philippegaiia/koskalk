<?php

namespace App\Models;

use Database\Factories\ProductionBatchIngredientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'production_batch_id',
    'ingredient_id',
    'raw_material_lot_id',
    'phase_key',
    'phase_name',
    'position',
    'ingredient_name',
    'percentage',
    'quantity',
    'unit',
    'price_per_kg',
    'line_cost',
    'ingredient_lot_number',
])]
class ProductionBatchIngredient extends Model
{
    /** @use HasFactory<ProductionBatchIngredientFactory> */
    use HasFactory;

    public function productionBatch(): BelongsTo
    {
        return $this->belongsTo(ProductionBatch::class);
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'percentage' => 'decimal:4',
            'quantity' => 'decimal:4',
            'price_per_kg' => 'decimal:4',
            'line_cost' => 'decimal:4',
        ];
    }
}
