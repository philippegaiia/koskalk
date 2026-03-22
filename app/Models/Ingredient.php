<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'source_file',
    'source_key',
    'source_code_prefix',
    'ingredient_family',
    'is_potentially_saponifiable',
    'requires_admin_review',
    'is_active',
    'source_data',
])]
class Ingredient extends Model
{
    public function versions(): HasMany
    {
        return $this->hasMany(IngredientVersion::class);
    }

    public function currentVersion(): HasOne
    {
        return $this->hasOne(IngredientVersion::class)->where('is_current', true);
    }

    protected function casts(): array
    {
        return [
            'is_potentially_saponifiable' => 'bool',
            'requires_admin_review' => 'bool',
            'is_active' => 'bool',
            'source_data' => 'array',
        ];
    }
}
