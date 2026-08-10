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

class ReopenProductionTask
{
    public function __construct(private readonly ProductionBenchAccess $access) {}

    public function handle(User $actor, ProductionTask $task): ProductionTask
    {
        $workspace = $task->workspace;

        if ($workspace === null) {
            throw ValidationException::withMessages(['task' => __('production_bench.production.validation.task_workspace_missing')]);
        }

        $this->access->assertWritable($actor, $workspace);

        return DB::transaction(function () use ($actor, $task): ProductionTask {
            $taskReference = ProductionTask::query()->findOrFail($task->id);
            $productionReference = ProductionRun::query()->find($taskReference->production_run_id);
            $workspace = $productionReference === null
                ? null
                : Workspace::withoutGlobalScopes()->lockForUpdate()->find($productionReference->workspace_id);
            $production = $workspace === null
                ? null
                : ProductionRun::query()
                    ->where('workspace_id', $workspace->id)
                    ->lockForUpdate()
                    ->find($taskReference->production_run_id);

            if ($production === null || $workspace === null || (int) $taskReference->workspace_id !== (int) $workspace->id) {
                throw ValidationException::withMessages(['task' => __('production_bench.production.validation.task_workspace_mismatch')]);
            }

            $this->access->assertWritable($actor, $workspace);
            $tasks = ProductionTask::query()
                ->where('production_run_id', $production->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $lockedTask = $tasks->firstWhere('id', $task->id);

            if (! $lockedTask instanceof ProductionTask) {
                throw ValidationException::withMessages(['task' => __('production_bench.production.validation.task_production_mismatch')]);
            }

            $outputLot = StockLot::query()
                ->withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->where('production_run_id', $production->id)
                ->where('origin', StockLotOrigin::ProductionOutput)
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if (! in_array($production->status, [
                ProductionRunStatus::Draft,
                ProductionRunStatus::Scheduled,
                ProductionRunStatus::Reserved,
                ProductionRunStatus::InProduction,
                ProductionRunStatus::Completed,
            ], true)) {
                throw ValidationException::withMessages(['task' => __('production_bench.production.validation.task_reopen_not_allowed')]);
            }

            if ($outputLot?->status === StockLotStatus::Released) {
                throw ValidationException::withMessages([
                    'task' => __('production_bench.production.validation.task_reopen_after_release'),
                ]);
            }

            if ($lockedTask->completed_at === null) {
                throw ValidationException::withMessages(['task' => __('production_bench.production.validation.task_not_completed')]);
            }

            $lockedTask->update(['completed_at' => null]);

            return $lockedTask->fresh(['productionRun', 'employee']);
        }, attempts: 5);
    }
}
