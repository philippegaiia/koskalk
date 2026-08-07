<?php

namespace App\Actions\Production;

use App\Models\ProductionRun;
use App\Models\User;
use App\Models\Workspace;
use App\ProductionRunStatus;
use App\Services\ProductionBenchAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class DeleteProductionRun
{
    public function __construct(
        private readonly ProductionBenchAccess $access,
    ) {}

    /**
     * Delete a draft or scheduled production that has no reservations. A
     * permanent batch number alone does not protect a run: assigning one may
     * be a mistake, and only reserved stock must keep the record.
     */
    public function handle(User $actor, ProductionRun $production): void
    {
        $workspace = $production->workspace;

        if (! $workspace instanceof Workspace) {
            throw ValidationException::withMessages([
                'production' => 'The production workspace could not be found.',
            ]);
        }

        $this->access->assertWritable($actor, $workspace);

        DB::transaction(function () use ($actor, $production): void {
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
        }, attempts: 5);
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
