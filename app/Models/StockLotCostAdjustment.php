<?php

namespace App\Models;

use App\Enums\StockLotCostAdjustmentType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable([
    'workspace_id',
    'stock_lot_id',
    'compensates_adjustment_id',
    'type',
    'amount',
    'currency',
    'costing_amount',
    'costing_currency',
    'exchange_rate',
    'exchange_rate_date',
    'exchange_rate_provider',
    'exchange_rate_is_manual',
    'reason',
    'created_by_user_id',
])]
class StockLotCostAdjustment extends Model
{
    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Stock lot cost adjustments are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Stock lot cost adjustments are immutable.'));
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class)->withoutGlobalScopes();
    }

    public function stockLot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class);
    }

    public function compensatesAdjustment(): BelongsTo
    {
        return $this->belongsTo(self::class, 'compensates_adjustment_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    protected function casts(): array
    {
        return [
            'type' => StockLotCostAdjustmentType::class,
            'amount' => 'decimal:9',
            'costing_amount' => 'decimal:9',
            'exchange_rate' => 'decimal:12',
            'exchange_rate_date' => 'date',
            'exchange_rate_is_manual' => 'boolean',
        ];
    }
}
