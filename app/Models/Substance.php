<?php

namespace App\Models;

use Database\Factories\SubstanceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name',
    'entity_type',
    'inci_name',
    'cas_number',
    'ec_number',
    'synonyms',
    'allergen_id',
    'source_name',
    'source_url',
    'notes',
    'source_data',
])]
class Substance extends Model
{
    /** @use HasFactory<SubstanceFactory> */
    use HasFactory;

    protected $table = 'substance_catalog';

    public function allergen(): BelongsTo
    {
        return $this->belongsTo(Allergen::class);
    }

    public function ingredientEntries(): HasMany
    {
        return $this->hasMany(IngredientSubstanceEntry::class);
    }

    public function regulatoryRegimeRules(): HasMany
    {
        return $this->hasMany(RegulatoryRegimeSubstanceRule::class);
    }

    protected function casts(): array
    {
        return [
            'synonyms' => 'array',
            'source_data' => 'array',
        ];
    }
}
