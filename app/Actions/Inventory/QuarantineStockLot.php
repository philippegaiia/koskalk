<?php

namespace App\Actions\Inventory;

use App\Models\StockLot;
use App\Models\User;
use App\Services\ProductionBenchAccess;
use App\StockLotStatus;
use Illuminate\Support\Facades\DB;

class QuarantineStockLot
{
    public function __construct(private readonly ProductionBenchAccess $access) {}

    public function handle(User $actor, StockLot $lot, ?string $note = null): StockLot
    {
        $this->access->assertWritable($actor, $lot->workspace);

        return DB::transaction(function () use ($lot, $note): StockLot {
            $lockedLot = StockLot::query()->lockForUpdate()->findOrFail($lot->id);
            $lockedLot->update([
                'status' => StockLotStatus::Quarantined,
                'available_from' => null,
                'released_at' => null,
                'released_by_user_id' => null,
                'release_note' => $note,
            ]);

            return $lockedLot->refresh();
        }, attempts: 5);
    }
}
