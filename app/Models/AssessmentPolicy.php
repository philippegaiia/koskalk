<?php

namespace App\Models;

use App\Models\Concerns\HasAppendOnlyVersions;
use Database\Factories\AssessmentPolicyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Global scientific-assumption defaults used when a profile or dossier gives no value.
 */
#[Fillable([
    'version', 'is_current', 'superseded_at',
    'default_dermal_absorption_percent', 'default_oral_bioavailability_percent', 'mos_threshold', 'body_weight_kg',
    'source_reference', 'change_note', 'published_by_user_id',
])]
class AssessmentPolicy extends Model
{
    use HasAppendOnlyVersions;

    /** @use HasFactory<AssessmentPolicyFactory> */
    use HasFactory;

    public static function versionScopeColumns(): array
    {
        return [];
    }

    protected function casts(): array
    {
        return [
            'is_current' => 'bool',
            'superseded_at' => 'datetime',
            'default_dermal_absorption_percent' => 'decimal:4',
            'default_oral_bioavailability_percent' => 'decimal:4',
            'mos_threshold' => 'decimal:4',
            'body_weight_kg' => 'decimal:3',
        ];
    }
}
