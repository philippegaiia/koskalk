<?php

namespace App\Models;

use Database\Factories\FattyAcidFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'key',
    'name',
    'short_name',
    'chain_length',
    'double_bonds',
    'saturation_class',
    'iodine_factor',
    'default_group_key',
    'display_order',
    'is_core',
    'is_active',
    'default_hidden_below_percent',
    'source_data',
])]
class FattyAcid extends Model
{
    /** @use HasFactory<FattyAcidFactory> */
    use HasFactory;

    public function ingredientVersionEntries(): HasMany
    {
        return $this->hasMany(IngredientVersionFattyAcid::class);
    }

    protected function casts(): array
    {
        return [
            'chain_length' => 'integer',
            'double_bonds' => 'integer',
            'iodine_factor' => 'decimal:3',
            'display_order' => 'integer',
            'is_core' => 'bool',
            'is_active' => 'bool',
            'default_hidden_below_percent' => 'decimal:3',
            'source_data' => 'array',
        ];
    }
}
