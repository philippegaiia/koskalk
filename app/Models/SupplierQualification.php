<?php

namespace App\Models;

use App\Enums\SupplierQualificationStatus;
use Database\Factories\SupplierQualificationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * "This supplier's grade of this ingredient is validated for our dossiers" — a DIP fact, separate from purchasing listings.
 */
#[Fillable([
    'workspace_id',
    'ingredient_id',
    'supplier_id',
    'supplier_reference',
    'status',
    'validated_by_user_id',
    'validated_at',
    'notes',
])]
class SupplierQualification extends Model
{
    /** @use HasFactory<SupplierQualificationFactory> */
    use HasFactory;

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class)->withoutGlobalScopes();
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function evidenceItems(): HasMany
    {
        return $this->hasMany(DossierEvidenceItem::class);
    }

    protected function casts(): array
    {
        return [
            'status' => SupplierQualificationStatus::class,
            'validated_at' => 'datetime',
        ];
    }
}
