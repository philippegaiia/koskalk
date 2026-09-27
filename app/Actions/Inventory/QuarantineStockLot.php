<?php

namespace App\Actions\Inventory;

use App\Enums\StockLotStatus;
use App\Models\StockLot;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProductionBenchAccess;
use Illuminate\Support\Facades\DB;

class QuarantineStockLot
{
    public function __construct(private readonly ProductionBenchAccess $access) {}

    public function handle(User $actor, StockLot $lot, ?string $note = null): StockLot
    {
        $this->access->assertWritable($actor, $lot->workspace);

        return DB::transaction(function () use ($actor, $lot, $note): StockLot {
            $workspace = Workspace::withoutGlobalScopes()->lockForUpdate()->findOrFail($lot->workspace_id);
            $this->access->assertWritable($actor, $workspace);
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
