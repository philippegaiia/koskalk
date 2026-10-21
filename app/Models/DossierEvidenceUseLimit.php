<?php

namespace App\Models;

use Database\Factories\DossierEvidenceUseLimitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A maximum use level stated by an IFRA certificate (per IFRA category) or an RCE (per product class).
 */
#[Fillable(['dossier_evidence_item_id', 'ifra_product_category_id', 'product_class_label', 'max_percent'])]
class DossierEvidenceUseLimit extends Model
{
    /** @use HasFactory<DossierEvidenceUseLimitFactory> */
    use HasFactory;

    public function item(): BelongsTo
    {
        return $this->belongsTo(DossierEvidenceItem::class, 'dossier_evidence_item_id');
    }

    public function ifraProductCategory(): BelongsTo
    {
        return $this->belongsTo(IfraProductCategory::class);
    }

    protected function casts(): array
    {
        return ['max_percent' => 'decimal:6'];
    }
}
