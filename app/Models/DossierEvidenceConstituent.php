<?php

namespace App\Models;

use App\Enums\ConstituentKind;
use Database\Factories\DossierEvidenceConstituentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A maximum content declared by a supplier document: allergen (%), impurity (ppm) or restricted substance (%).
 */
#[Fillable(['dossier_evidence_item_id', 'kind', 'allergen_id', 'substance_id', 'max_value', 'unit', 'note'])]
class DossierEvidenceConstituent extends Model
{
    /** @use HasFactory<DossierEvidenceConstituentFactory> */
    use HasFactory;

    public function item(): BelongsTo
    {
        return $this->belongsTo(DossierEvidenceItem::class, 'dossier_evidence_item_id');
    }

    public function allergen(): BelongsTo
    {
        return $this->belongsTo(Allergen::class);
    }

    public function substance(): BelongsTo
    {
        return $this->belongsTo(Substance::class);
    }

    protected function casts(): array
    {
        return ['kind' => ConstituentKind::class, 'max_value' => 'decimal:6'];
    }
}
