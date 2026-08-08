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

class ReleaseProductionStock
{
    public function __construct(private readonly ProductionBenchAccess $access) {}

    public function handle(
        User $actor,
        ProductionRun $production,
        ?int $productionRequirementId = null,
    ): ProductionRun {
        $workspace = $production->workspace;

        if (! $workspace instanceof Workspace) {
            throw ValidationException::withMessages([
                'production' => 'The production workspace could not be found.',
            ]);
        }

        $this->access->assertWritable($actor, $workspace);

        return DB::transaction(function () use ($actor, $production, $productionRequirementId): ProductionRun {
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

            if (! in_array($lockedProduction->status, [ProductionRunStatus::Scheduled, ProductionRunStatus::Reserved], true)) {
                throw ValidationException::withMessages([
                    'production' => 'Only planned or stock-prepared productions can release stock.',
                ]);
            }

            $reservations = StockReservation::query()
                ->where('production_run_id', $lockedProduction->id)
                ->when($productionRequirementId !== null, fn ($query) => $query->where('production_requirement_id', $productionRequirementId))
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

            if ($lockedProduction->status === ProductionRunStatus::Reserved && ! $this->isFullyReserved($lockedProduction)) {
                $lockedProduction->update(['status' => ProductionRunStatus::Scheduled]);
            }

            return $lockedProduction->fresh(['requirements', 'recipe']);
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
