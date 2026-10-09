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

class CancelProduction
{
    public function __construct(private readonly ProductionMutationGuard $guard, private readonly ProductionBenchAccess $access) {}

    public function handle(User $actor, ProductionRun $production, string $reason,
        ?ProductionEditingContext $editing = null,
    ): ProductionRun {
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

        return $this->guard->run($actor, [$production->id], $editing, function (User $actor, Workspace $lockedWorkspace, Collection $productions, ProductionMutationScope $scope) use ($production, $reason): ProductionMutationResult {

            if ($lockedWorkspace === null) {
                throw ValidationException::withMessages([
                    'production' => __('production_bench.production.workspace_missing'),
                ]);
            }

            $this->access->assertWritable($actor, $lockedWorkspace);

            $lockedProduction = $productions[$production->id];

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

            return new ProductionMutationResult($lockedProduction->fresh(['requirements', 'tasks', 'recipe']), [$lockedProduction->id]);
        });
    }
}
