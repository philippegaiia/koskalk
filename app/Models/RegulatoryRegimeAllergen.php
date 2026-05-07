<?php

namespace App\Models;

use Database\Factories\RegulatoryRegimeAllergenFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'regulatory_regime_id',
    'allergen_id',
    'declaration_label',
    'rinse_off_threshold_percent',
    'leave_on_threshold_percent',
    'threshold_operator',
    'group_key',
    'group_label',
    'is_active',
    'effective_from',
    'effective_until',
    'source_reference',
    'source_data',
])]
class RegulatoryRegimeAllergen extends Model
{
    /** @use HasFactory<RegulatoryRegimeAllergenFactory> */
    use HasFactory;

    public function regulatoryRegime(): BelongsTo
    {
        return $this->belongsTo(RegulatoryRegime::class);
    }

    public function allergen(): BelongsTo
    {
        return $this->belongsTo(Allergen::class);
    }

    protected function casts(): array
    {
        return [
            'rinse_off_threshold_percent' => 'decimal:5',
            'leave_on_threshold_percent' => 'decimal:5',
            'is_active' => 'bool',
            'effective_from' => 'date',
            'effective_until' => 'date',
            'source_data' => 'array',
        ];
    }
}
