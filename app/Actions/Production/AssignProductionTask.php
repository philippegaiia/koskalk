<?php

namespace App\Actions\Production;

use App\Enums\ProductionRunStatus;
use App\Models\Department;
use App\Models\Employee;
use App\Models\ProductionRun;
use App\Models\ProductionTask;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProductionBenchAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssignProductionTask
{
    public function __construct(private readonly ProductionBenchAccess $access) {}

    public function handle(
        User $actor,
        ProductionTask $task,
        ?int $departmentId = null,
        ?int $employeeId = null,
    ): ProductionTask {
        $workspace = $task->workspace;

        if ($workspace === null) {
            throw ValidationException::withMessages(['task' => 'The task workspace could not be found.']);
        }

        $this->access->assertWritable($actor, $workspace);

        return DB::transaction(function () use ($actor, $departmentId, $employeeId, $task): ProductionTask {
            $lockedTask = ProductionTask::query()->lockForUpdate()->findOrFail($task->id);
            $production = ProductionRun::query()->lockForUpdate()->find($lockedTask->production_run_id);

            if ($production === null) {
                throw ValidationException::withMessages(['task' => 'The production could not be found.']);
            }

            $workspace = Workspace::withoutGlobalScopes()->lockForUpdate()->find($production->workspace_id);

            if ($workspace === null || (int) $lockedTask->workspace_id !== (int) $workspace->id) {
                throw ValidationException::withMessages(['task' => 'The task does not belong to this workspace.']);
            }

            $this->access->assertWritable($actor, $workspace);

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

            return $lockedTask->fresh(['productionRun', 'employee', 'department']);
        }, attempts: 5);
    }
}
