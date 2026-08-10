<?php

namespace App\Actions\Production;

use App\Enums\ProductionRunStatus;
use App\Enums\StockMovementType;
use App\Enums\StockReservationStatus;
use App\Models\ProductionConsumption;
use App\Models\ProductionRun;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\StockReservation;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProductionBenchAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AbortProduction
{
    public function __construct(
        private readonly ProductionBenchAccess $access,
    ) {}

    /**
     * Abort a running production with reconciliation: post consumption for
     * every recorded actual, release all remaining active reservations, and
     * close the run as aborted. No output lot and no cost snapshot.
     */
    public function handle(User $actor, ProductionRun $production, string $reason): ProductionRun
    {
        $workspace = $production->workspace;

        if (! $workspace instanceof Workspace) {
            throw ValidationException::withMessages([
                'production' => __('production_bench.production.workspace_missing'),
            ]);
        }

        $this->access->assertWritable($actor, $workspace);

        return DB::transaction(function () use ($actor, $production, $reason): ProductionRun {
            $lockedWorkspace = Workspace::withoutGlobalScopes()
                ->lockForUpdate()
                ->findOrFail($production->workspace_id);
            $lockedProduction = ProductionRun::query()
                ->lockForUpdate()
                ->findOrFail($production->id);

            $this->access->assertWritable($actor, $lockedWorkspace);

            if ($lockedProduction->status !== ProductionRunStatus::InProduction) {
                throw ValidationException::withMessages([
                    'production' => __('production_bench.production.validation.abort_running_only'),
                ]);
            }

            $reason = trim($reason);

            if ($reason === '' || mb_strlen($reason) > 2000) {
                throw ValidationException::withMessages([
                    'abort_reason' => __('production_bench.production.validation.abort_reason_invalid'),
                ]);
            }

            $consumption = ProductionConsumption::query()
                ->where('production_run_id', $lockedProduction->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($consumption as $row) {
                $lot = StockLot::query()
                    ->withoutGlobalScopes()
                    ->lockForUpdate()
                    ->findOrFail($row->stock_lot_id);

                StockMovement::query()->create([
                    'stock_lot_id' => $lot->id,
                    'workspace_id' => $lockedWorkspace->id,
                    'type' => StockMovementType::ProductionConsumption,
                    'quantity_delta' => '-'.$row->quantity,
                    'original_quantity' => $row->quantity,
                    'original_unit' => $row->unit_snapshot,
                    'occurred_at' => now(),
                    'actor_user_id' => $actor->id,
                    'source_type' => (new ProductionRun)->getMorphClass(),
                    'source_id' => $lockedProduction->id,
                    'idempotency_key' => (string) Str::uuid(),
                    'note' => $row->note,
                ]);
            }

            StockReservation::query()
                ->where('production_run_id', $lockedProduction->id)
                ->where('status', StockReservationStatus::Active)
                ->lockForUpdate()
                ->get()
                ->each(function (StockReservation $reservation): void {
                    $reservation->update([
                        'status' => StockReservationStatus::Released,
                        'released_at' => now(),
                    ]);
                });

            $lockedProduction->update([
                'status' => ProductionRunStatus::Aborted,
                'aborted_at' => now(),
                'aborted_by_user_id' => $actor->id,
                'abort_reason' => $reason,
            ]);

            return $lockedProduction->fresh(['requirements', 'consumption']);
        }, attempts: 5);
    }
}
