<?php

namespace App\Actions\Production;

use App\Enums\ProductionRunStatus;
use App\Enums\StockReservationStatus;
use App\Models\ProductionRun;
use App\Models\StockReservation;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProductionBenchAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CancelProduction
{
    public function __construct(private readonly ProductionBenchAccess $access) {}

    public function handle(User $actor, ProductionRun $production, string $reason): ProductionRun
    {
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 1000) {
            throw ValidationException::withMessages([
                'cancellationReason' => __('production_bench.production.cancel_reason_invalid'),
            ]);
        }

        $workspace = $production->workspace;

        if ($workspace === null) {
            throw ValidationException::withMessages([
                'production' => __('production_bench.production.workspace_missing'),
            ]);
        }

        $this->access->assertWritable($actor, $workspace);

        return DB::transaction(function () use ($actor, $production, $reason, $workspace): ProductionRun {
            $lockedWorkspace = Workspace::withoutGlobalScopes()
                ->whereKey($workspace->id)
                ->lockForUpdate()
                ->first();

            if ($lockedWorkspace === null) {
                throw ValidationException::withMessages([
                    'production' => __('production_bench.production.workspace_missing'),
                ]);
            }

            $this->access->assertWritable($actor, $lockedWorkspace);

            $lockedProduction = ProductionRun::query()
                ->where('workspace_id', $lockedWorkspace->id)
                ->lockForUpdate()
                ->findOrFail($production->id);

            if (! in_array($lockedProduction->status, [ProductionRunStatus::Draft, ProductionRunStatus::Scheduled, ProductionRunStatus::Reserved], true)) {
                throw ValidationException::withMessages([
                    'production' => __('production_bench.production.cancel_not_allowed'),
                ]);
            }

            $reservations = StockReservation::query()
                ->where('production_run_id', $lockedProduction->id)
                ->where('status', StockReservationStatus::Active)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($reservations as $reservation) {
                $reservation->update([
                    'status' => StockReservationStatus::Cancelled,
                    'cancelled_at' => now(),
                ]);
            }

            $lockedProduction->update([
                'status' => ProductionRunStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by_user_id' => $actor->id,
                'cancellation_reason' => $reason,
            ]);

            return $lockedProduction->fresh(['requirements', 'tasks', 'recipe']);
        }, attempts: 5);
    }
}
