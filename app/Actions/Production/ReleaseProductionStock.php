<?php

namespace App\Actions\Production;

use App\Models\ProductionRun;
use App\Models\StockReservation;
use App\Models\User;
use App\Models\Workspace;
use App\ProductionRunStatus;
use App\Services\ProductionBenchAccess;
use App\StockReservationStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReleaseProductionStock
{
    public function __construct(private readonly ProductionBenchAccess $access) {}

    public function handle(User $actor, ProductionRun $production): ProductionRun
    {
        $workspace = $production->workspace;

        if (! $workspace instanceof Workspace) {
            throw ValidationException::withMessages([
                'production' => 'The production workspace could not be found.',
            ]);
        }

        $this->access->assertWritable($actor, $workspace);

        return DB::transaction(function () use ($actor, $production): ProductionRun {
            $lockedProduction = ProductionRun::query()
                ->lockForUpdate()
                ->findOrFail($production->id);
            $lockedWorkspace = Workspace::withoutGlobalScopes()
                ->lockForUpdate()
                ->find($lockedProduction->workspace_id);

            if (! $lockedWorkspace instanceof Workspace) {
                throw ValidationException::withMessages([
                    'production' => 'The production workspace could not be found.',
                ]);
            }

            $this->access->assertWritable($actor, $lockedWorkspace);

            if (! in_array($lockedProduction->status, [ProductionRunStatus::Scheduled, ProductionRunStatus::Reserved], true)) {
                throw ValidationException::withMessages([
                    'production' => 'Only planned or stock-prepared productions can release stock.',
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
                    'status' => StockReservationStatus::Released,
                    'released_at' => now(),
                ]);
            }

            if ($lockedProduction->status === ProductionRunStatus::Reserved) {
                $lockedProduction->update(['status' => ProductionRunStatus::Scheduled]);
            }

            return $lockedProduction->fresh(['requirements', 'recipe']);
        }, attempts: 5);
    }
}
