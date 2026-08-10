<?php

namespace App\Services\Production;

use App\Enums\StockLotStatus;
use App\Models\StockLot;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class ConsumableStockLotPolicy
{
    public function assertConsumable(StockLot $lot, CarbonInterface $onDate, string $field): void
    {
        $eligibilityDate = Carbon::instance($onDate)->startOfDay();

        if ($lot->status !== StockLotStatus::Released
            || ($lot->available_from !== null && $lot->available_from->isAfter($eligibilityDate))
            || ($lot->expires_at !== null && $lot->expires_at->isBefore($eligibilityDate))) {
            throw ValidationException::withMessages([
                $field => __('production_bench.production.validation.stock_lot_not_available'),
            ]);
        }
    }
}
