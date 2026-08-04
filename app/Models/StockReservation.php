<?php

namespace App\Models;

use App\StockReservationStatus;
use Database\Factories\StockReservationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'production_run_id',
    'production_requirement_id',
    'stock_lot_id',
    'quantity',
    'status',
    'created_by_user_id',
    'idempotency_key',
    'released_at',
    'cancelled_at',
])]
class StockReservation extends Model
{
    /** @use HasFactory<StockReservationFactory> */
    use HasFactory;

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class)->withoutGlobalScopes();
    }

    public function productionRun(): BelongsTo
    {
        return $this->belongsTo(ProductionRun::class);
    }

    public function productionRequirement(): BelongsTo
    {
        return $this->belongsTo(ProductionRequirement::class);
    }

    public function stockLot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:9',
            'status' => StockReservationStatus::class,
            'released_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }
}
