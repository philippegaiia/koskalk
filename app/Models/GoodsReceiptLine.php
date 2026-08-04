<?php

namespace App\Models;

use App\ListingPriceBasis;
use Database\Factories\GoodsReceiptLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable([
    'goods_receipt_id', 'purchase_order_line_id', 'supplier_listing_id', 'stock_lot_id', 'packs_received',
    'actual_quantity', 'original_quantity', 'original_unit', 'historical_total_cost', 'costing_total_cost',
    'receipt_price_basis', 'receipt_price_amount', 'receipt_price_unit', 'purchase_format_price', 'currency',
    'costing_currency', 'exchange_rate', 'exchange_rate_date', 'exchange_rate_provider', 'exchange_rate_is_manual',
    'supplier_batch_number', 'expires_at', 'notes', 'previous_material_price_snapshot',
])]
class GoodsReceiptLine extends Model
{
    /** @use HasFactory<GoodsReceiptLineFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Goods receipt lines are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Goods receipt lines are immutable.'));
    }

    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    public function purchaseOrderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class);
    }

    public function supplierListing(): BelongsTo
    {
        return $this->belongsTo(SupplierListing::class);
    }

    public function stockLot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class);
    }

    protected function casts(): array
    {
        return [
            'packs_received' => 'integer',
            'actual_quantity' => 'decimal:9',
            'original_quantity' => 'decimal:9',
            'historical_total_cost' => 'decimal:9',
            'costing_total_cost' => 'decimal:9',
            'receipt_price_basis' => ListingPriceBasis::class,
            'receipt_price_amount' => 'decimal:9',
            'purchase_format_price' => 'decimal:9',
            'exchange_rate' => 'decimal:12',
            'exchange_rate_date' => 'date',
            'exchange_rate_is_manual' => 'boolean',
            'expires_at' => 'date',
            'previous_material_price_snapshot' => 'array',
        ];
    }
}
