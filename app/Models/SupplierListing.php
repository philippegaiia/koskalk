<?php

namespace App\Models;

use App\Enums\ListingPriceBasis;
use App\Enums\OrganicStatus;
use App\Enums\StockUnitKind;
use App\Models\Concerns\HasPublicId;
use Database\Factories\SupplierListingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'workspace_id', 'supplier_id', 'ingredient_id', 'packaging_item_id', 'supplier_sku',
    'supplier_item_name', 'purchase_format', 'container', 'unit_kind', 'canonical_quantity_per_purchase_format',
    'net_quantity', 'net_unit', 'price_basis', 'price_amount', 'price_unit', 'price_recorded_at', 'total_price',
    'currency', 'minimum_packs', 'organic_status', 'notes', 'is_active',
])]
class SupplierListing extends Model
{
    /** @use HasFactory<SupplierListingFactory> */
    use HasFactory;

    use HasPublicId;

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class)->withoutGlobalScopes();
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class)->withoutGlobalScopes();
    }

    public function packagingItem(): BelongsTo
    {
        return $this->belongsTo(PackagingItem::class, 'packaging_item_id');
    }

    public function purchaseOrderLines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class);
    }

    public function stockLots(): HasMany
    {
        return $this->hasMany(StockLot::class);
    }

    protected function casts(): array
    {
        return [
            'unit_kind' => StockUnitKind::class,
            'canonical_quantity_per_purchase_format' => 'decimal:9',
            'net_quantity' => 'decimal:9',
            'price_basis' => ListingPriceBasis::class,
            'price_amount' => 'decimal:9',
            'price_recorded_at' => 'datetime',
            'total_price' => 'decimal:9',
            'minimum_packs' => 'integer',
            'organic_status' => OrganicStatus::class,
            'is_active' => 'boolean',
        ];
    }
}
