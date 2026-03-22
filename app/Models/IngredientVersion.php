<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'ingredient_id',
    'version',
    'is_current',
    'display_name',
    'display_name_en',
    'display_name_fr',
    'inci_name',
    'soap_inci_naoh_name',
    'soap_inci_koh_name',
    'cas_number',
    'ec_number',
    'unit',
    'price_eur',
    'is_active',
    'is_manufactured',
    'source_file',
    'source_key',
    'source_data',
])]
class IngredientVersion extends Model
{
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    protected function casts(): array
    {
        return [
            'is_current' => 'bool',
            'price_eur' => 'decimal:2',
            'is_active' => 'bool',
            'is_manufactured' => 'bool',
            'source_data' => 'array',
        ];
    }
}
