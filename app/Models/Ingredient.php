<?php

namespace App\Models;

use App\IngredientCategory;
use Database\Factories\IngredientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'source_file',
    'source_key',
    'source_code_prefix',
    'category',
    'is_potentially_saponifiable',
    'requires_admin_review',
    'is_active',
    'source_data',
])]
class Ingredient extends Model
{
    /** @use HasFactory<IngredientFactory> */
    use HasFactory;

    public function versions(): HasMany
    {
        return $this->hasMany(IngredientVersion::class);
    }

    public function currentVersion(): HasOne
    {
        return $this->hasOne(IngredientVersion::class)->where('is_current', true);
    }

    public function recipeItems(): HasMany
    {
        return $this->hasMany(RecipeItem::class);
    }

    public function isAvailableForInitialSoapCalculation(): bool
    {
        return $this->category === IngredientCategory::CarrierOil
            && $this->is_potentially_saponifiable;
    }

    protected function casts(): array
    {
        return [
            'category' => IngredientCategory::class,
            'is_potentially_saponifiable' => 'bool',
            'requires_admin_review' => 'bool',
            'is_active' => 'bool',
            'source_data' => 'array',
        ];
    }
}
