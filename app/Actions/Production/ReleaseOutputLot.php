<?php

namespace App\Actions\Production;

use App\Enums\ProductionRunStatus;
use App\Enums\StockLotOrigin;
use App\Enums\StockLotStatus;
use App\Models\ProductionRun;
use App\Models\ProductionTask;
use App\Models\StockLot;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProductionBenchAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReleaseOutputLot
{
    public function __construct(
        private readonly ProductionBenchAccess $access,
    ) {}

    /**
     * Release a quarantined output lot after all linked production tasks have
     * been completed. An early release requires an explicit confirmation.
     */
    public function handle(
        User $actor,
        StockLot $lot,
        ?string $note = null,
        bool $earlyReleaseConfirmed = false,
    ): StockLot {
        $this->access->assertWritable($actor, $lot->workspace);

        return DB::transaction(function () use ($actor, $earlyReleaseConfirmed, $lot, $note): StockLot {
            $lotReference = StockLot::query()
                ->withoutGlobalScopes()
                ->findOrFail($lot->id);
            $workspace = Workspace::withoutGlobalScopes()
                ->lockForUpdate()
                ->find($lotReference->workspace_id);

            if (! $workspace instanceof Workspace) {
                throw ValidationException::withMessages([
                    'lot' => __('production_bench.production.validation.output_lot_workspace_missing'),
                ]);
            }

            $this->access->assertWritable($actor, $workspace);

            if ($lotReference->production_run_id === null) {
                throw ValidationException::withMessages([
                    'lot' => __('production_bench.production.validation.output_lot_unlinked'),
                ]);
            }

            $production = ProductionRun::query()
                ->where('workspace_id', $workspace->id)
                ->lockForUpdate()
                ->find($lotReference->production_run_id);

            if (! $production instanceof ProductionRun) {
                throw ValidationException::withMessages([
                    'lot' => __('production_bench.production.validation.output_production_missing'),
                ]);
            }

            $tasks = ProductionTask::query()
                ->where('production_run_id', $production->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $lockedLot = StockLot::query()
                ->withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->lockForUpdate()
                ->findOrFail($lot->id);

            if ($production->status !== ProductionRunStatus::Completed) {
                throw ValidationException::withMessages([
                    'lot' => __('production_bench.production.validation.output_release_requires_completed'),
                ]);
            }

            if ($lockedLot->origin !== StockLotOrigin::ProductionOutput) {
                throw ValidationException::withMessages([
                    'lot' => __('production_bench.production.validation.output_release_requires_production_lot'),
                ]);
            }

            if ($lockedLot->status !== StockLotStatus::Quarantined) {
                throw ValidationException::withMessages([
                    'lot' => __('production_bench.production.validation.output_release_requires_quarantined'),
                ]);
            }

            if ($lockedLot->estimated_ready_on !== null
                && $lockedLot->estimated_ready_on->isFuture()
                && ! $earlyReleaseConfirmed) {
                throw ValidationException::withMessages([
                    'early_release_confirmation' => __('production_bench.production.validation.early_release_confirmation', [
                        'date' => $lockedLot->estimated_ready_on->toDateString(),
                    ]),
                ]);
            }

            $incompleteTasks = $tasks->whereNull('completed_at');

            if ($incompleteTasks->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'lot' => __('production_bench.production.validation.output_release_tasks_incomplete', [
                        'tasks' => $incompleteTasks->pluck('name_snapshot')->implode(', '),
                    ]),
                ]);
            }

            $lockedLot->update([
                'status' => StockLotStatus::Released,
                'released_at' => now(),
                'released_by_user_id' => $actor->id,
                'release_note' => $note !== null && trim($note) !== '' ? trim($note) : null,
            ]);

            return $lockedLot->refresh();
        }, attempts: 5);
    }
}
