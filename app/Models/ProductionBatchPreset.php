<?php

namespace App\Models;

use App\MassUnit;
use App\Models\Concerns\HasPublicId;
use Database\Factories\ProductionBatchPresetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'recipe_id',
    'name',
    'basis_quantity_grams',
    'basis_input_value',
    'basis_input_unit',
    'expected_units',
    'is_default',
    'is_active',
])]
class ProductionBatchPreset extends Model
{
    /** @use HasFactory<ProductionBatchPresetFactory> */
    use HasFactory;

    use HasPublicId;

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class)->withoutGlobalScopes();
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class)->withoutGlobalScopes();
    }

    protected function casts(): array
    {
        return [
            'basis_quantity_grams' => 'decimal:9',
            'basis_input_value' => 'decimal:9',
            'basis_input_unit' => MassUnit::class,
            'expected_units' => 'integer',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
