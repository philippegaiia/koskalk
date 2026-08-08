<?php

namespace App\Models;

use App\Enums\StockMovementType;
use App\Enums\StockUnitKind;
use App\Models\Concerns\HasPublicId;
use Database\Factories\StockMovementFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

#[Fillable([
    'workspace_id',
    'stock_lot_id',
    'type',
    'quantity_delta',
    'original_quantity',
    'original_unit',
    'occurred_at',
    'actor_user_id',
    'source_type',
    'source_id',
    'reversal_of_stock_movement_id',
    'idempotency_key',
    'note',
])]
class StockMovement extends Model
{
    /** @use HasFactory<StockMovementFactory> */
    use HasFactory;

    use HasPublicId;

    protected $attributes = [
        'type' => StockMovementType::OpeningBalance->value,
    ];

    protected static function booted(): void
    {
        static::creating(function (StockMovement $movement): void {
            $lot = StockLot::query()->findOrFail($movement->stock_lot_id);

            if ((int) $movement->workspace_id !== (int) $lot->workspace_id) {
                throw new DomainException('Movement and lot must belong to the same workspace.');
            }

            if (
                $lot->unit_kind === StockUnitKind::Count
                && bccomp((string) $movement->quantity_delta, bcadd((string) $movement->quantity_delta, '0', 0), 9) !== 0
            ) {
                throw new DomainException('Count movements must use whole quantities.');
            }
        });

        static::updating(fn (): never => throw new LogicException('Posted stock movements are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Posted stock movements are immutable.'));
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class)->withoutGlobalScopes();
    }

    public function stockLot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_stock_movement_id');
    }

    protected function casts(): array
    {
        return [
            'type' => StockMovementType::class,
            'quantity_delta' => 'decimal:9',
            'original_quantity' => 'decimal:9',
            'occurred_at' => 'datetime',
        ];
    }
}
