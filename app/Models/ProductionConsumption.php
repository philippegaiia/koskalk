<?php

namespace App\Models;

use App\Enums\ProductionConsumptionKind;
use Database\Factories\ProductionConsumptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'production_run_id',
    'production_requirement_id',
    'stock_lot_id',
    'kind',
    'subject_name_snapshot',
    'quantity',
    'unit_snapshot',
    'price_per_unit',
    'line_cost',
    'recorded_by_user_id',
    'note',
])]
class ProductionConsumption extends Model
{
    /** @use HasFactory<ProductionConsumptionFactory> */
    use HasFactory;

    protected $table = 'production_consumption';

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
        return $this->belongsTo(StockLot::class)->withoutGlobalScopes();
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    protected function casts(): array
    {
        return [
            'kind' => ProductionConsumptionKind::class,
            'quantity' => 'decimal:9',
            'price_per_unit' => 'decimal:9',
            'line_cost' => 'decimal:9',
        ];
    }
}
