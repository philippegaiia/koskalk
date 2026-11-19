<?php

namespace App\Actions\Production;

use App\Enums\StockMovementType;
use App\Enums\StockReservationStatus;
use App\Models\ProductionRun;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Production\ProductionEditingContext;
use App\Services\Production\ProductionMutationResult;
use App\Services\ProductionBenchAccess;
use App\Services\ProductionMutationGuard;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class UndoPacking
{
    public function __construct(
        private readonly ProductionBenchAccess $access,
        private readonly ProductionMutationGuard $guard,
    ) {}

    public function handle(User $actor, ProductionRun $production, int $lotId, ProductionEditingContext $editing): void
    {
        $this->access->assertWritable($actor, $production->workspace);

        $this->guard->run($actor, [$production->id], $editing, function (
            User $actor, Workspace $workspace, Collection $productions,
        ) use ($production, $lotId): ProductionMutationResult {
            $this->access->assertWritable($actor, $workspace);
            $run = $productions[$production->id];
            $lot = $run->packagedLots()->where('workspace_id', $workspace->id)->lockForUpdate()->findOrFail($lotId);
            $original = $lot->movements()
                ->where('type', StockMovementType::PackagingOutput)
                ->where('source_type', $lot->getMorphClass())
                ->where('source_id', $lot->id)
                ->first();
            if ($original !== null && $lot->movements()
                ->where('type', StockMovementType::PackagingReversal)
                ->where('reversal_of_stock_movement_id', $original->id)->exists()) {
                return new ProductionMutationResult(null, []);
            }

            if ($original === null || $lot->movements()->count() !== 1
                || $lot->reservations()->where('status', StockReservationStatus::Active)->exists()) {
                throw ValidationException::withMessages(['packing' => __('production_bench.packaging.validation.undo_used')]);
            }

            $draws = StockMovement::query()->where('workspace_id', $workspace->id)
                ->where('source_type', $lot->getMorphClass())
                ->where('source_id', $lot->id)
                ->where('type', StockMovementType::PackagingConsumption)
                ->orderBy('id')->get();
            if ($run->outputLot === null || ! $draws->contains('stock_lot_id', $run->outputLot->id)) {
                throw ValidationException::withMessages(['packing' => __('production_bench.packaging.validation.undo_source_missing')]);
            }

            StockLot::query()->where('workspace_id', $workspace->id)
                ->whereIn('id', $draws->pluck('stock_lot_id'))->orderBy('id')->lockForUpdate()->get();

            foreach ($draws->push($original) as $movement) {
                StockMovement::query()->create([
                    'workspace_id' => $workspace->id,
                    'stock_lot_id' => $movement->stock_lot_id,
                    'type' => StockMovementType::PackagingReversal,
                    'quantity_delta' => bcmul($movement->quantity_delta, '-1', 9),
                    'original_quantity' => $movement->original_quantity,
                    'original_unit' => $movement->original_unit,
                    'occurred_at' => now(),
                    'actor_user_id' => $actor->id,
                    'source_type' => $lot->getMorphClass(),
                    'source_id' => $lot->id,
                    'reversal_of_stock_movement_id' => $movement->id,
                    'idempotency_key' => 'undo-packing:'.$movement->id,
                ]);
            }

            return new ProductionMutationResult(null, [$run->id]);
        });
    }
}
