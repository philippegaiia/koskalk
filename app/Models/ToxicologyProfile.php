<?php

namespace App\Models;

use App\Enums\NoaelRoute;
use App\Models\Concerns\HasAppendOnlyVersions;
use Database\Factories\ToxicologyProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One version of the toxicological data of an assessed material (a substance_catalog row).
 */
#[Fillable([
    'substance_id',
    'version',
    'is_current',
    'superseded_at',
    'noael_mg_kg_bw_day', 'noael_route', 'noael_species', 'noael_study',
    'oral_bioavailability_percent', 'ld50_mg_kg', 'ld50_route',
    'dermal_absorption_percent', 'cmax_percent', 'cmax_origin',
    'adi_mg_kg_bw_day', 'adi_reasoning', 'ttc_class', 'ttc_mg_kg_bw_day', 'ttc_reasoning',
    'nesil_ug_cm2',
    'skin_irritation_note', 'eye_irritation_note', 'sensitisation_note', 'phototoxicity_note', 'cmr_note', 'clp_classification',
    'references',
    'value_references',
    'change_note',
    'published_by_user_id',
])]
class ToxicologyProfile extends Model
{
    use HasAppendOnlyVersions;

    /** @use HasFactory<ToxicologyProfileFactory> */
    use HasFactory;

    /** Data columns copied between versions. */
    public const VALUE_COLUMNS = [
        'noael_mg_kg_bw_day', 'noael_route', 'noael_species', 'noael_study',
        'oral_bioavailability_percent', 'ld50_mg_kg', 'ld50_route',
        'dermal_absorption_percent', 'cmax_percent', 'cmax_origin',
        'adi_mg_kg_bw_day', 'adi_reasoning', 'ttc_class', 'ttc_mg_kg_bw_day', 'ttc_reasoning',
        'nesil_ug_cm2',
        'skin_irritation_note', 'eye_irritation_note', 'sensitisation_note', 'phototoxicity_note', 'cmr_note', 'clp_classification',
    ];

    /** Numeric values that must each cite at least one numbered reference. */
    public const REFERENCED_VALUES = [
        'noael_mg_kg_bw_day', 'oral_bioavailability_percent', 'ld50_mg_kg', 'dermal_absorption_percent',
        'cmax_percent', 'adi_mg_kg_bw_day', 'ttc_mg_kg_bw_day', 'nesil_ug_cm2',
    ];

    public static function versionScopeColumns(): array
    {
        return ['substance_id'];
    }

    public function substance(): BelongsTo
    {
        return $this->belongsTo(Substance::class);
    }

    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by_user_id');
    }

    protected function casts(): array
    {
        return [
            'is_current' => 'bool',
            'superseded_at' => 'datetime',
            'noael_route' => NoaelRoute::class,
            'noael_mg_kg_bw_day' => 'decimal:9',
            'oral_bioavailability_percent' => 'decimal:4',
            'ld50_mg_kg' => 'decimal:9',
            'dermal_absorption_percent' => 'decimal:4',
            'cmax_percent' => 'decimal:6',
            'adi_mg_kg_bw_day' => 'decimal:9',
            'ttc_mg_kg_bw_day' => 'decimal:9',
            'nesil_ug_cm2' => 'decimal:6',
            'references' => 'array',
            'value_references' => 'array',
        ];
    }
}
