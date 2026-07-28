<?php

namespace App\Models;

use Database\Factories\GoodsReceiptLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'goods_receipt_id', 'purchase_order_line_id', 'stock_lot_id', 'packs_received',
    'actual_quantity', 'original_quantity', 'original_unit', 'historical_total_cost',
    'supplier_batch_number', 'expires_at',
])]
class GoodsReceiptLine extends Model
{
    /** @use HasFactory<GoodsReceiptLineFactory> */
    use HasFactory;

    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    public function purchaseOrderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class);
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
            'expires_at' => 'date',
        ];
    }
}
