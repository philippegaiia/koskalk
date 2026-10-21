<?php

namespace App\Models;

use Database\Factories\WorkspaceDossierSettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Responsible Person, sites, DIP numbering and default assessor of one workspace.
 */
#[Fillable([
    'workspace_id',
    'responsible_person_name',
    'responsible_person_address',
    'responsible_person_siret',
    'manufacturer_name',
    'manufacturer_address',
    'packer_name',
    'packer_address',
    'numbering_prefix',
    'assessor_name',
    'assessor_address',
    'assessor_qualification',
])]
class WorkspaceDossierSetting extends Model
{
    /** @use HasFactory<WorkspaceDossierSettingFactory> */
    use HasFactory;

    public const EDITABLE = [
        'responsible_person_name',
        'responsible_person_address',
        'responsible_person_siret',
        'manufacturer_name',
        'manufacturer_address',
        'packer_name',
        'packer_address',
        'numbering_prefix',
        'assessor_name',
        'assessor_address',
        'assessor_qualification',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class)->withoutGlobalScopes();
    }
}
