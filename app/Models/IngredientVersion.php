<?php

namespace App\Models;

use Database\Factories\IngredientVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
    /** @use HasFactory<IngredientVersionFactory> */
    use HasFactory;

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function sapProfile(): HasOne
    {
        return $this->hasOne(IngredientSapProfile::class);
    }

    public function allergenEntries(): HasMany
    {
        return $this->hasMany(IngredientAllergenEntry::class);
    }

    public function ifraCertificates(): HasMany
    {
        return $this->hasMany(IfraCertificate::class);
    }

    public function recipeItems(): HasMany
    {
        return $this->hasMany(RecipeItem::class);
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
