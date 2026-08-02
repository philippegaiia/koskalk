<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use App\OrganicStatus;
use App\StockLotOrigin;
use App\StockLotStatus;
use App\StockUnitKind;
use Database\Factories\StockLotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable([
    'workspace_id',
    'ingredient_id',
    'packaging_item_id',
    'supplier_listing_id',
    'organic_status',
    'internal_lot_code',
    'supplier_batch_number',
    'origin',
    'unit_kind',
    'status',
    'stocked_at',
    'expires_at',
    'available_from',
    'released_at',
    'released_by_user_id',
    'release_note',
    'provenance_complete',
    'historical_unit_cost',
    'currency',
    'notes',
])]
class StockLot extends Model
{
    /** @use HasFactory<StockLotFactory> */
    use HasFactory;

    use HasPublicId;

    protected $attributes = [
        'origin' => StockLotOrigin::OpeningBalance->value,
        'unit_kind' => StockUnitKind::Mass->value,
        'status' => StockLotStatus::Quarantined->value,
        'provenance_complete' => false,
        'organic_status' => OrganicStatus::Unknown->value,
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class)->withoutGlobalScopes();
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class)->withoutGlobalScopes();
    }

    public function packagingItem(): BelongsTo
    {
        return $this->belongsTo(PackagingItem::class, 'packaging_item_id');
    }

    public function supplierListing(): BelongsTo
    {
        return $this->belongsTo(SupplierListing::class);
    }

    public function releasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by_user_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(ProductionDocument::class, 'documentable');
    }

    public function subjectName(): string
    {
        return $this->ingredient?->localizedDisplayName()
            ?? $this->packagingItem?->name
            ?? 'Unknown stock item';
    }

    protected function casts(): array
    {
        return [
            'origin' => StockLotOrigin::class,
            'unit_kind' => StockUnitKind::class,
            'status' => StockLotStatus::class,
            'stocked_at' => 'date',
            'expires_at' => 'date',
            'available_from' => 'date',
            'released_at' => 'datetime',
            'provenance_complete' => 'boolean',
            'historical_unit_cost' => 'decimal:9',
            'organic_status' => OrganicStatus::class,
        ];
    }
}
