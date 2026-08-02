<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use App\ProcurementStage;
use App\PurchaseOrderStatus;
use Database\Factories\PurchaseOrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'workspace_id', 'supplier_id', 'reference', 'stage', 'status', 'quotation_reference',
    'quotation_requested_at', 'quotation_snapshot', 'price_confirmed_at', 'ordered_at',
    'expected_at', 'issued_at', 'purchase_order_snapshot', 'supplier_snapshot',
    'delivery_address_snapshot', 'currency', 'shipping_amount', 'discount_amount',
    'tax_amount', 'notes', 'created_by_user_id',
])]
class PurchaseOrder extends Model
{
    /** @use HasFactory<PurchaseOrderFactory> */
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

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class);
    }

    protected function casts(): array
    {
        return [
            'stage' => ProcurementStage::class,
            'status' => PurchaseOrderStatus::class,
            'quotation_requested_at' => 'datetime',
            'quotation_snapshot' => 'array',
            'price_confirmed_at' => 'datetime',
            'ordered_at' => 'date',
            'expected_at' => 'date',
            'issued_at' => 'datetime',
            'purchase_order_snapshot' => 'array',
            'supplier_snapshot' => 'array',
            'delivery_address_snapshot' => 'array',
            'shipping_amount' => 'decimal:9',
            'discount_amount' => 'decimal:9',
            'tax_amount' => 'decimal:9',
        ];
    }
}
