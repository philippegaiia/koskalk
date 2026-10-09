<?php

namespace App\Actions\Production;

use App\Enums\ProductionRunStatus;
use App\Enums\StockReservationStatus;
use App\Models\ProductionRun;
use App\Models\StockReservation;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Production\ProductionEditingContext;
use App\Services\Production\ProductionMutationResult;
use App\Services\Production\ProductionMutationScope;
use App\Services\ProductionBenchAccess;
use App\Services\ProductionMutationGuard;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class ReleaseProductionStock
{
    public function __construct(private readonly ProductionMutationGuard $guard, private readonly ProductionBenchAccess $access) {}

    public function handle(
        User $actor,
        ProductionRun $production,
        ?int $productionRequirementId = null,
        ?ProductionEditingContext $editing = null,
    ): ProductionRun {
        $workspace = $production->workspace;

        if (! $workspace instanceof Workspace) {
            throw ValidationException::withMessages([
                'production' => __('production_bench.production.workspace_missing'),
            ]);
        }

        $this->access->assertWritable($actor, $workspace);

        return $this->guard->run($actor, [$production->id], $editing, function (User $actor, Workspace $lockedWorkspace, Collection $productions, ProductionMutationScope $scope) use ($production, $productionRequirementId): ProductionMutationResult {
            $lockedProduction = $productions[$production->id];

            if (! $lockedWorkspace instanceof Workspace) {
                throw ValidationException::withMessages([
                    'production' => __('production_bench.production.workspace_missing'),
                ]);
            }

            $this->access->assertWritable($actor, $lockedWorkspace);

            if (! in_array($lockedProduction->status, [ProductionRunStatus::Scheduled, ProductionRunStatus::Reserved], true)) {
                throw ValidationException::withMessages([
                    'production' => __('production_bench.production.validation.release_stock_status_invalid'),
                ]);
            }

            $reservations = StockReservation::query()
                ->where('production_run_id', $lockedProduction->id)
                ->when($productionRequirementId !== null, fn ($query) => $query->where('production_requirement_id', $productionRequirementId))
                ->where('status', StockReservationStatus::Active)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $originalStatus = $lockedProduction->status;
            foreach ($reservations as $reservation) {
                $reservation->update([
                    'status' => StockReservationStatus::Released,
                    'released_at' => now(),
                ]);
            }

            if ($lockedProduction->status === ProductionRunStatus::Reserved && ! $this->isFullyReserved($lockedProduction)) {
                $lockedProduction->update(['status' => ProductionRunStatus::Scheduled]);
            }

            return new ProductionMutationResult($lockedProduction->fresh(['requirements', 'recipe']), ($reservations->isNotEmpty() || $originalStatus !== $lockedProduction->status) ? [$lockedProduction->id] : []);
        });
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
