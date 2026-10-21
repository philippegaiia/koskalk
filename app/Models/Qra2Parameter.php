<?php

namespace App\Models;

use App\Models\Concerns\HasAppendOnlyVersions;
use Database\Factories\Qra2ParameterFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reviewed QRA2 preset: the IFRA method constants for one QRA2 category under one amendment
 * (IFRA guidance Table 6: SAF, CEL and the aggregate exposure adjustment factor).
 * The adjustment factor is part of the method and is applied; not applying it is an expert deviation
 * that must state its reason.
 */
#[Fillable([
    'ifra_product_category_id', 'ifra_amendment_id', 'preset_label', 'version', 'is_current', 'superseded_at',
    'sensitization_assessment_factor', 'consumer_exposure_level_mg_cm2_day', 'qra2_factor', 'applies_qra2_factor',
    'factor_deviation_reason',
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

    public static function publicationRules(): array
    {
        return [
            'ifra_product_category_id' => ['required', 'integer', 'exists:ifra_product_categories,id'],
            'ifra_amendment_id' => ['required', 'integer', 'exists:ifra_amendments,id'],
            'preset_label' => ['required', 'string', 'max:255'],
            'sensitization_assessment_factor' => ['required', 'numeric', 'gt:0'],
            'consumer_exposure_level_mg_cm2_day' => ['required', 'numeric', 'gt:0'],
            'qra2_factor' => ['required', 'numeric', 'gt:0', 'lte:1'],
            'applies_qra2_factor' => ['required', 'boolean'],
            'factor_deviation_reason' => ['nullable', 'required_if_declined:applies_qra2_factor', 'string'],
            'source_reference' => ['required', 'string'],
        ];
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
