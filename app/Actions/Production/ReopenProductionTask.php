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
use App\Services\Production\ProductionEditingContext;
use App\Services\Production\ProductionMutationResult;
use App\Services\ProductionBenchAccess;
use App\Services\ProductionMutationGuard;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class ReopenProductionTask
{
    public function __construct(private readonly ProductionMutationGuard $guard, private readonly ProductionBenchAccess $access) {}

    public function handle(User $actor, ProductionTask $task,
        ?ProductionEditingContext $editing = null,
    ): ProductionTask {
        $workspace = $task->workspace;

        if ($workspace === null) {
            throw ValidationException::withMessages(['task' => __('production_bench.production.validation.task_workspace_missing')]);
        }

        $this->access->assertWritable($actor, $workspace);

        $parentId = ProductionTask::query()->where('workspace_id', $workspace->id)->whereKey($task->id)->value('production_run_id');
        if ($parentId === null || ! ProductionRun::query()->where('workspace_id', $workspace->id)->whereKey($parentId)->exists()) {
            throw ValidationException::withMessages(['task' => __('production_bench.production.validation.task_production_missing')]);
        }
        $parentId = (int) $parentId;

        return $this->guard->run($actor, [$parentId], $editing, function (User $actor, Workspace $workspace, Collection $productions) use ($task, $parentId): ProductionMutationResult {
            $production = $productions[$parentId];
            $lockedTask = ProductionTask::query()->where('workspace_id', $workspace->id)->where('production_run_id', $parentId)->lockForUpdate()->find($task->id);
            if ($lockedTask === null) {
                throw ValidationException::withMessages(['task' => __('production_bench.production.validation.task_production_missing')]);
            }
            $before = [$lockedTask->employee_id, $lockedTask->department_id, $lockedTask->scheduled_for?->toDateString(), $lockedTask->scheduling_mode, $lockedTask->completed_at?->toIso8601String(), $production->planned_for?->toDateString()];

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

            return new ProductionMutationResult($lockedTask->fresh(['productionRun', 'employee']), ($before !== [$lockedTask->employee_id, $lockedTask->department_id, $lockedTask->scheduled_for?->toDateString(), $lockedTask->scheduling_mode, $lockedTask->completed_at?->toIso8601String(), $production->planned_for?->toDateString()]) ? [$parentId] : []);
        });
    }
}
