<?php

namespace App\Actions\Inventory;

use App\Models\StockLot;
use App\Models\User;
use App\Services\ProductionBenchAccess;
use App\StockLotStatus;
use Illuminate\Support\Facades\DB;

class ReleaseStockLot
{
    public function __construct(private readonly ProductionBenchAccess $access) {}

    public function handle(User $actor, StockLot $lot, ?string $note = null): StockLot
    {
        $this->access->assertWritable($actor, $lot->workspace);

        return DB::transaction(function () use ($actor, $lot, $note): StockLot {
            $lockedLot = StockLot::query()->lockForUpdate()->findOrFail($lot->id);
            $lockedLot->update([
                'status' => StockLotStatus::Released,
                'available_from' => now()->toDateString(),
                'released_at' => now(),
                'released_by_user_id' => $actor->id,
                'release_note' => $note,
            ]);

            return $lockedLot->refresh();
        }, attempts: 5);
    }
}
