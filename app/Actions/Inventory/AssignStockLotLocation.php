<?php

namespace App\Actions\Inventory;

use App\Models\StockLot;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Inventory\StorageLocationSelection;
use App\Services\ProductionBenchAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssignStockLotLocation
{
    public function __construct(private readonly ProductionBenchAccess $access, private readonly StorageLocationSelection $locations) {}

    public function handle(User $actor, StockLot $lot, ?int $locationId): StockLot
    {
        return DB::transaction(function () use ($actor, $lot, $locationId): StockLot {
            $workspace = Workspace::withoutGlobalScopes()->lockForUpdate()->findOrFail($lot->workspace_id);
            $this->access->assertWritable($actor, $workspace);
            $current = StockLot::query()->where('workspace_id', $workspace->id)->lockForUpdate()->findOrFail($lot->id);
            if (! $workspace->uses_storage_locations || ($current->ingredient_id === null && $current->packaging_item_id === null)) {
                throw ValidationException::withMessages(['storage_location_id' => __('locations.validation.disabled')]);
            }
            $current->update(['storage_location_id' => $this->locations->validate($workspace, $locationId)]);

            return $current->fresh();
        }, attempts: 5);
    }
}
