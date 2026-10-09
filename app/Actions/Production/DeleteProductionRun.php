<?php

namespace App\Actions\Production;

use App\Enums\ProductionRunStatus;
use App\Models\ProductionRun;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Production\ProductionEditingContext;
use App\Services\Production\ProductionMutationResult;
use App\Services\Production\ProductionMutationScope;
use App\Services\ProductionBenchAccess;
use App\Services\ProductionMutationGuard;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class DeleteProductionRun
{
    public function __construct(private readonly ProductionMutationGuard $guard,
        private readonly ProductionBenchAccess $access,
    ) {}

    /**
     * Delete a draft or scheduled production that has no reservations. A
     * permanent batch number alone does not protect a run: assigning one may
     * be a mistake, and only reserved stock must keep the record.
     */
    public function handle(User $actor, ProductionRun $production,
        ?ProductionEditingContext $editing = null,
    ): void {
        $workspace = $production->workspace;

        if (! $workspace instanceof Workspace) {
            throw ValidationException::withMessages([
                'production' => __('production_bench.production.workspace_missing'),
            ]);
        }

        $this->access->assertCanConfigure($actor, $workspace);

        $this->guard->run($actor, [$production->id], $editing, function (User $actor, Workspace $lockedWorkspace, Collection $productions, ProductionMutationScope $scope) use ($production): ProductionMutationResult {
            $lockedProduction = $productions[$production->id];

            if (! $lockedWorkspace instanceof Workspace) {
                throw ValidationException::withMessages([
                    'production' => __('production_bench.production.workspace_missing'),
                ]);
            }

            $this->access->assertCanConfigure($actor, $lockedWorkspace);

            if (! in_array($lockedProduction->status, [
                ProductionRunStatus::Draft,
                ProductionRunStatus::Scheduled,
            ], true)) {
                throw ValidationException::withMessages([
                    'production' => __('production_bench.production.delete_blocked_status'),
                ]);
            }

            $this->assertNoActiveReservations($lockedProduction);

            $lockedProduction->delete();

            return new ProductionMutationResult(null, [$lockedProduction->id]);
        });
    }

    private function assertNoActiveReservations(ProductionRun $production): void
    {
        if (! Schema::hasTable('stock_reservations')) {
            return;
        }

        $hasActiveReservations = DB::table('stock_reservations')
            ->where('production_run_id', $production->id)
            ->whereNotIn('status', ['released', 'cancelled'])
            ->exists();

        if ($hasActiveReservations) {
            throw ValidationException::withMessages([
                'production' => __('production_bench.production.delete_blocked_reservations'),
            ]);
        }
    }
}
