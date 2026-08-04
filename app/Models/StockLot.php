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
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use LogicException;

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
    'costing_unit_cost',
    'currency',
    'costing_currency',
    'exchange_rate',
    'exchange_rate_date',
    'exchange_rate_provider',
    'exchange_rate_is_manual',
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

    protected static function booted(): void
    {
        static::updating(function (StockLot $lot): void {
            $immutableAcquisitionAttributes = [
                'workspace_id',
                'supplier_listing_id',
                'ingredient_id',
                'packaging_item_id',
                'internal_lot_code',
                'supplier_batch_number',
                'unit_kind',
                'expires_at',
                'historical_unit_cost',
                'costing_unit_cost',
                'currency',
                'costing_currency',
                'exchange_rate',
                'exchange_rate_date',
                'exchange_rate_provider',
                'exchange_rate_is_manual',
                'origin',
                'organic_status',
                'stocked_at',
            ];

            if (
                $lot->isDirty($immutableAcquisitionAttributes)
                && GoodsReceiptLine::query()->where('stock_lot_id', $lot->getKey())->exists()
            ) {
                throw new LogicException('Receipt lot acquisition fields are immutable.');
            }
        });
    }

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

    public function goodsReceiptLine(): HasOne
    {
        return $this->hasOne(GoodsReceiptLine::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(StockReservation::class);
    }

    public function costAdjustments(): HasMany
    {
        return $this->hasMany(StockLotCostAdjustment::class);
    }

    public function effectiveCostingTotalCost(): string
    {
        $receiptLine = $this->relationLoaded('goodsReceiptLine')
            ? $this->getRelation('goodsReceiptLine')
            : $this->goodsReceiptLine()->first();

        if (! $receiptLine instanceof GoodsReceiptLine) {
            throw new LogicException('A stock lot cost requires a goods receipt line.');
        }

        $baseCost = $receiptLine->costing_total_cost
            ?? bcmul(
                (string) ($this->costing_unit_cost ?? $this->historical_unit_cost),
                (string) $receiptLine->actual_quantity,
                9,
            );
        $adjustments = $this->relationLoaded('costAdjustments')
            ? $this->getRelation('costAdjustments')
            : $this->costAdjustments()->get();

        return $adjustments->reduce(
            fn (string $total, StockLotCostAdjustment $adjustment): string => bcadd(
                $total,
                (string) $adjustment->costing_amount,
                9,
            ),
            $baseCost,
        );
    }

    public function effectiveCostingUnitCost(): string
    {
        $receiptLine = $this->relationLoaded('goodsReceiptLine')
            ? $this->getRelation('goodsReceiptLine')
            : $this->goodsReceiptLine()->first();

        if (! $receiptLine instanceof GoodsReceiptLine || bccomp((string) $receiptLine->actual_quantity, '0', 9) <= 0) {
            throw new LogicException('A stock lot cost requires a positive receipt quantity.');
        }

        return bcround(
            bcdiv($this->effectiveCostingTotalCost(), (string) $receiptLine->actual_quantity, 18),
            9,
        );
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(ProductionDocument::class, 'documentable');
    }

    public function subjectName(): string
    {
        return $this->ingredient?->localizedDisplayName()
            ?? $this->packagingItem?->name
            ?? __('production_bench.inventory.unknown_item');
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
            'costing_unit_cost' => 'decimal:9',
            'exchange_rate' => 'decimal:12',
            'exchange_rate_date' => 'date',
            'exchange_rate_is_manual' => 'boolean',
            'organic_status' => OrganicStatus::class,
        ];
    }
}
