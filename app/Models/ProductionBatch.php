<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Database\Factories\ProductionBatchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id',
    'recipe_id',
    'recipe_version_id',
    'recipe_name',
    'recipe_version_number',
    'product_family_slug',
    'production_batch_number',
    'manufacture_date',
    'batch_basis_label',
    'batch_basis_value',
    'batch_basis_unit',
    'units_produced',
    'currency',
    'ingredient_total',
    'packaging_total',
    'total_cost',
    'cost_per_unit',
    'production_notes',
])]
class ProductionBatch extends Model
{
    /** @use HasFactory<ProductionBatchFactory> */
    use HasFactory;

    use HasPublicId;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    public function recipeVersion(): BelongsTo
    {
        return $this->belongsTo(RecipeVersion::class);
    }

    public function ingredients(): HasMany
    {
        return $this->hasMany(ProductionBatchIngredient::class)
            ->orderBy('phase_key')
            ->orderBy('position');
    }

    public function packagingItems(): HasMany
    {
        return $this->hasMany(ProductionBatchPackagingItem::class)->orderBy('position');
    }

    protected function casts(): array
    {
        return [
            'manufacture_date' => 'date',
            'batch_basis_value' => 'decimal:3',
            'units_produced' => 'integer',
            'ingredient_total' => 'decimal:4',
            'packaging_total' => 'decimal:4',
            'total_cost' => 'decimal:4',
            'cost_per_unit' => 'decimal:4',
        ];
    }
}
