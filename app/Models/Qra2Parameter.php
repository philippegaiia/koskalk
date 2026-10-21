<?php

namespace App\Models;

use App\Models\Concerns\HasAppendOnlyVersions;
use Database\Factories\Qra2ParameterFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * IFRA QRA2 method parameters for one IFRA category under one amendment.
 * Whether the QRA2 factor multiplies Cmax is an explicit, recorded decision (spec §11 open question 1).
 */
#[Fillable([
    'ifra_product_category_id', 'ifra_amendment_id', 'version', 'is_current', 'superseded_at',
    'sensitization_assessment_factor', 'consumer_exposure_level_mg_cm2_day', 'qra2_factor', 'applies_qra2_factor',
    'source_reference', 'change_note', 'published_by_user_id',
])]
class Qra2Parameter extends Model
{
    use HasAppendOnlyVersions;

    /** @use HasFactory<Qra2ParameterFactory> */
    use HasFactory;

    public static function versionScopeColumns(): array
    {
        return ['ifra_product_category_id', 'ifra_amendment_id'];
    }

    public function ifraProductCategory(): BelongsTo
    {
        return $this->belongsTo(IfraProductCategory::class);
    }

    public function ifraAmendment(): BelongsTo
    {
        return $this->belongsTo(IfraAmendment::class);
    }

    protected function casts(): array
    {
        return [
            'is_current' => 'bool',
            'superseded_at' => 'datetime',
            'sensitization_assessment_factor' => 'decimal:3',
            'consumer_exposure_level_mg_cm2_day' => 'decimal:6',
            'qra2_factor' => 'decimal:4',
            'applies_qra2_factor' => 'bool',
        ];
    }
}
