<?php

namespace App\Models;

use App\ListingPriceBasis;
use App\OrganicStatus;
use App\StockUnitKind;
use Database\Factories\PurchaseOrderLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'purchase_order_id', 'supplier_listing_id', 'ingredient_id', 'packaging_item_id',
    'supplier_sku', 'supplier_item_name', 'listing_name', 'unit_kind', 'ordered_packs', 'canonical_quantity_per_pack',
    'pack_price', 'price_basis', 'price_amount', 'price_unit', 'price_recorded_at',
    'currency', 'expected_quantity', 'expected_cost', 'organic_status',
])]
class PurchaseOrderLine extends Model
{
    /** @use HasFactory<PurchaseOrderLineFactory> */
    use HasFactory;

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function supplierListing(): BelongsTo
    {
        return $this->belongsTo(SupplierListing::class);
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class)->withoutGlobalScopes();
    }

    public function packagingItem(): BelongsTo
    {
        return $this->belongsTo(PackagingItem::class);
    }

    public function receiptLines(): HasMany
    {
        return $this->hasMany(GoodsReceiptLine::class);
    }

    protected function casts(): array
    {
        return [
            'unit_kind' => StockUnitKind::class,
            'ordered_packs' => 'integer',
            'canonical_quantity_per_pack' => 'decimal:9',
            'pack_price' => 'decimal:9',
            'price_basis' => ListingPriceBasis::class,
            'price_amount' => 'decimal:9',
            'price_recorded_at' => 'datetime',
            'expected_quantity' => 'decimal:9',
            'expected_cost' => 'decimal:9',
            'organic_status' => OrganicStatus::class,
        ];
    }
}
