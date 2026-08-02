<?php

namespace App\Models;

use App\GoodsReceiptStatus;
use App\Models\Concerns\HasPublicId;
use Database\Factories\GoodsReceiptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'workspace_id', 'purchase_order_id', 'delivery_reference', 'received_at', 'status', 'notes',
    'received_by_user_id', 'idempotency_key', 'reversed_at', 'reversed_by_user_id',
])]
class GoodsReceipt extends Model
{
    /** @use HasFactory<GoodsReceiptFactory> */
    use HasFactory;

    use HasPublicId;

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(GoodsReceiptLine::class);
    }

    protected function casts(): array
    {
        return [
            'status' => GoodsReceiptStatus::class,
            'received_at' => 'date',
            'reversed_at' => 'datetime',
        ];
    }
}
