<?php

namespace App\Models;

use App\Enums\EvidenceType;
use Database\Factories\DossierEvidenceItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use LogicException;

/**
 * What a stored file proves, which annex it belongs to and what it is attached to. Replaced, never deleted.
 */
#[Fillable([
    'workspace_id', 'dossier_evidence_file_id', 'type', 'supplier_qualification_id',
    'issued_on', 'revision_due_on', 'notes', 'retired_at', 'replaced_by_id', 'withdrawn_reason', 'withdrawn_by_user_id', 'created_by_user_id',
])]
class DossierEvidenceItem extends Model
{
    /** @use HasFactory<DossierEvidenceItemFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Evidence items are retained; replace them instead.'));
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(DossierEvidenceFile::class, 'dossier_evidence_file_id');
    }

    public function supplierQualification(): BelongsTo
    {
        return $this->belongsTo(SupplierQualification::class);
    }

    public function recipes(): BelongsToMany
    {
        return $this->belongsToMany(Recipe::class, 'dossier_evidence_item_recipe')->withoutGlobalScopes();
    }

    public function replacedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaced_by_id');
    }

    public function isActive(): bool
    {
        return $this->retired_at === null;
    }

    /**
     * @param  Builder<DossierEvidenceItem>  $query
     * @return Builder<DossierEvidenceItem>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('retired_at');
    }

    protected function casts(): array
    {
        return [
            'type' => EvidenceType::class,
            'issued_on' => 'date',
            'revision_due_on' => 'date',
            'retired_at' => 'datetime',
        ];
    }
}
