<?php

namespace App\Models;

use App\Enums\GoodsReceiptSource;
use App\Enums\GoodsReceiptStatus;
use App\Models\Concerns\HasPublicId;
use Database\Factories\GoodsReceiptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use LogicException;

#[Fillable([
    'workspace_id', 'supplier_id', 'purchase_order_id', 'source', 'delivery_reference', 'received_at', 'status', 'notes',
    'received_by_user_id', 'idempotency_key', 'reversed_at', 'reversed_by_user_id', 'reversal_reason',
])]
class GoodsReceipt extends Model
{
    /** @use HasFactory<GoodsReceiptFactory> */
    use HasFactory;

    use HasPublicId;

    protected $attributes = [
        'source' => GoodsReceiptSource::PurchaseOrder->value,
    ];

    protected static function booted(): void
    {
        static::updating(function (GoodsReceipt $receipt): void {
            $allowedAttributes = [
                'status',
                'reversed_at',
                'reversed_by_user_id',
                'reversal_reason',
                'updated_at',
            ];

            if (array_diff(array_keys($receipt->getDirty()), $allowedAttributes) !== []) {
                throw new LogicException('Posted goods receipt identity is immutable.');
            }
        });

        static::deleting(fn (): never => throw new LogicException('Posted goods receipts are immutable.'));
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class)->withoutGlobalScopes();
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(GoodsReceiptLine::class);
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(ProductionDocument::class, 'documentable');
    }

    protected function casts(): array
    {
        return [
            'source' => GoodsReceiptSource::class,
            'status' => GoodsReceiptStatus::class,
            'received_at' => 'date',
            'reversed_at' => 'datetime',
        ];
    }
}
