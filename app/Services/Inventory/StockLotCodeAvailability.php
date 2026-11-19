<?php

namespace App\Services\Inventory;

use App\Models\ProductionRun;
use App\Models\ProductionRunNumberIssuance;
use App\Models\StockLot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StockLotCodeAvailability
{
    public function isTaken(int $workspaceId, string $code): bool
    {
        return $this->hasStockLots($workspaceId, [$code]) || $this->hasIssuedBatchNumbers($workspaceId, [$code]);
    }

    /** @param list<string> $codes */
    public function hasStockLots(int $workspaceId, array $codes): bool
    {
        return $this->stockLots($workspaceId, $codes)->exists();
    }

    /** @param list<string> $codes */
    public function conflictingStockLotCode(int $workspaceId, array $codes): ?string
    {
        return $this->stockLots($workspaceId, $codes)->orderBy('id')->value('internal_lot_code');
    }

    /** @param list<string> $codes */
    private function stockLots(int $workspaceId, array $codes): Builder
    {
        return StockLot::query()->where('workspace_id', $workspaceId)
            ->whereIn(DB::raw('LOWER(internal_lot_code)'), array_map(Str::lower(...), $codes));
    }

    /** @param list<string> $codes */
    public function hasIssuedBatchNumbers(int $workspaceId, array $codes): bool
    {
        $codes = array_map(Str::lower(...), $codes);

        return ProductionRunNumberIssuance::query()->where('workspace_id', $workspaceId)
            ->whereIn(DB::raw('LOWER(batch_number)'), $codes)->exists()
            || ProductionRun::query()->where('workspace_id', $workspaceId)
                ->whereIn(DB::raw('LOWER(batch_number)'), $codes)->exists();
    }
}
