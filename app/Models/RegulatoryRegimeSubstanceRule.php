<?php

namespace App\Models;

use Database\Factories\RegulatoryRegimeSubstanceRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'regulatory_regime_id',
    'substance_id',
    'rule_type',
    'rinse_off_max_percent',
    'leave_on_max_percent',
    'threshold_operator',
    'exposure_scope',
    'label_warning_text',
    'is_active',
    'effective_from',
    'effective_until',
    'source_reference',
    'source_data',
])]
class RegulatoryRegimeSubstanceRule extends Model
{
    /** @use HasFactory<RegulatoryRegimeSubstanceRuleFactory> */
    use HasFactory;

    public function regulatoryRegime(): BelongsTo
    {
        return $this->belongsTo(RegulatoryRegime::class);
    }

    public function substance(): BelongsTo
    {
        return $this->belongsTo(Substance::class);
    }

    protected function casts(): array
    {
        return [
            'rinse_off_max_percent' => 'decimal:5',
            'leave_on_max_percent' => 'decimal:5',
            'is_active' => 'bool',
            'effective_from' => 'date',
            'effective_until' => 'date',
            'source_data' => 'array',
        ];
    }
}
