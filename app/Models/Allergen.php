<?php

namespace App\Models;

use Database\Factories\AllergenFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'source_name',
    'source_file',
    'inci_name',
    'cas_number',
    'ec_number',
    'common_name_en',
    'common_name_fr',
    'source_data',
])]
class Allergen extends Model
{
    /** @use HasFactory<AllergenFactory> */
    use HasFactory;

    protected $table = 'allergen_catalog';

    public function ingredientEntries(): HasMany
    {
        return $this->hasMany(IngredientAllergenEntry::class);
    }

    protected function casts(): array
    {
        return [
            'source_data' => 'array',
        ];
    }
}
