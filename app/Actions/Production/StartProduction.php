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

class StartProduction
{
    public function __construct(
        private readonly ProductionBenchAccess $access,
    ) {}

    /**
     * Start a reserved production. Starting requires full reservation
     * coverage and an assigned permanent batch number, and never performs
     * reservation itself.
     */
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
            $lockedWorkspace = Workspace::withoutGlobalScopes()
                ->lockForUpdate()
                ->find($production->workspace_id);
            $lockedProduction = ProductionRun::query()
                ->lockForUpdate()
                ->findOrFail($production->id);

            if (! $lockedWorkspace instanceof Workspace) {
                throw ValidationException::withMessages([
                    'production' => 'The production workspace could not be found.',
                ]);
            }

            $this->access->assertWritable($actor, $lockedWorkspace);

            if ($lockedProduction->status !== ProductionRunStatus::Reserved) {
                throw ValidationException::withMessages([
                    'production' => __('production_bench.production.start_blocked_status'),
                ]);
            }

            if ($lockedProduction->batch_number === null) {
                throw ValidationException::withMessages([
                    'production' => __('production_bench.production.start_blocked_number'),
                ]);
            }

            if (! $this->isFullyReserved($lockedProduction)) {
                throw ValidationException::withMessages([
                    'production' => __('production_bench.production.start_blocked_coverage'),
                ]);
            }

            $lockedProduction->update([
                'status' => ProductionRunStatus::InProduction,
                'started_at' => now(),
                'started_by_user_id' => $actor->id,
            ]);

            return $lockedProduction->fresh();
        }, attempts: 5);
    }

    private function isFullyReserved(ProductionRun $production): bool
    {
        $requirements = $production->requirements()->get();

        foreach ($requirements as $requirement) {
            $required = $requirement->ingredient_id !== null
                ? (string) $requirement->required_mass_grams
                : (string) $requirement->required_units;
            $reserved = '0.000000000';

            foreach (StockReservation::query()
                ->where('production_requirement_id', $requirement->id)
                ->where('status', StockReservationStatus::Active)
                ->lockForUpdate()
                ->get(['quantity']) as $reservation) {
                $reserved = bcadd($reserved, (string) $reservation->quantity, 9);
            }

            if (bccomp($reserved, $required, 9) < 0) {
                return false;
            }
        }

        return true;
    }
}
