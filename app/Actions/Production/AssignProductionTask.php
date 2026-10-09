<?php

namespace App\Actions\Production;

use App\Enums\ProductionRunStatus;
use App\Models\Department;
use App\Models\Employee;
use App\Models\ProductionRun;
use App\Models\ProductionTask;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Production\ProductionEditingContext;
use App\Services\Production\ProductionMutationResult;
use App\Services\ProductionBenchAccess;
use App\Services\ProductionMutationGuard;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class AssignProductionTask
{
    public function __construct(private readonly ProductionMutationGuard $guard, private readonly ProductionBenchAccess $access) {}

    public function handle(
        User $actor,
        ProductionTask $task,
        ?int $departmentId = null,
        ?int $employeeId = null,
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

        return $this->guard->run($actor, [$parentId], $editing, function (User $actor, Workspace $workspace, Collection $productions) use ($departmentId, $employeeId, $task, $parentId): ProductionMutationResult {
            $production = $productions[$parentId];
            $lockedTask = ProductionTask::query()->where('workspace_id', $workspace->id)->where('production_run_id', $parentId)->lockForUpdate()->find($task->id);
            if ($lockedTask === null) {
                throw ValidationException::withMessages(['task' => __('production_bench.production.validation.task_production_missing')]);
            }
            $before = [$lockedTask->employee_id, $lockedTask->department_id, $lockedTask->scheduled_for?->toDateString(), $lockedTask->scheduling_mode, $lockedTask->completed_at?->toIso8601String(), $production->planned_for?->toDateString()];

            if (in_array($production->status, [
                ProductionRunStatus::Completed,
                ProductionRunStatus::Cancelled,
                ProductionRunStatus::Aborted,
            ], true)) {
                throw ValidationException::withMessages([
                    'task' => 'Completed or cancelled productions are read-only.',
                ]);
            }

            if ($departmentId !== null && ! Department::query()
                ->where('workspace_id', $workspace->id)
                ->whereKey($departmentId)
                ->where('is_active', true)
                ->exists()) {
                throw ValidationException::withMessages([
                    'department' => 'Choose an active department from this workspace.',
                ]);
            }

            if ($employeeId !== null && ! Employee::query()
                ->where('workspace_id', $workspace->id)
                ->whereKey($employeeId)
                ->where('is_active', true)
                ->exists()) {
                throw ValidationException::withMessages([
                    'employee' => 'Choose an active employee from this workspace.',
                ]);
            }

            $lockedTask->update([
                'department_id' => $departmentId,
                'employee_id' => $employeeId,
            ]);

            return new ProductionMutationResult($lockedTask->fresh(['productionRun', 'employee', 'department']), ($before !== [$lockedTask->employee_id, $lockedTask->department_id, $lockedTask->scheduled_for?->toDateString(), $lockedTask->scheduling_mode, $lockedTask->completed_at?->toIso8601String(), $production->planned_for?->toDateString()]) ? [$parentId] : []);
        });
    }
}
