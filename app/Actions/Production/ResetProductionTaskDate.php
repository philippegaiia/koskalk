<?php

namespace App\Actions\Production;

use App\Enums\ProductionRunStatus;
use App\Models\ProductionRun;
use App\Models\ProductionTask;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Production\ProductionWorkingCalendar;
use App\Services\ProductionBenchAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ResetProductionTaskDate
{
    public function __construct(
        private readonly ProductionBenchAccess $access,
        private readonly ProductionWorkingCalendar $calendar,
    ) {}

    public function handle(User $actor, ProductionTask $task): ProductionTask
    {
        $workspace = $task->workspace;

        if ($workspace === null) {
            throw ValidationException::withMessages(['task' => 'The task workspace could not be found.']);
        }

        $this->access->assertWritable($actor, $workspace);

        return DB::transaction(function () use ($actor, $task): ProductionTask {
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

            if (! in_array($production->status, [
                ProductionRunStatus::Draft,
                ProductionRunStatus::Scheduled,
                ProductionRunStatus::Reserved,
            ], true)) {
                throw ValidationException::withMessages(['task' => 'Task dates cannot be changed after production starts.']);
            }

            if ($lockedTask->completed_at !== null) {
                throw ValidationException::withMessages(['task' => 'Completed tasks must be reopened before their date can be reset.']);
            }

            $anchorTaskId = ProductionTask::query()
                ->where('production_run_id', $production->id)
                ->where('days_after_production', 0)
                ->orderBy('id')
                ->value('id');

            if ((int) $anchorTaskId === (int) $lockedTask->id) {
                throw ValidationException::withMessages(['task' => 'The production task is the date anchor and cannot be reset independently.']);
            }

            if ($production->planned_for === null) {
                throw ValidationException::withMessages(['production' => 'Set a production date before resetting a task.']);
            }

            $lockedTask->update([
                'scheduled_for' => $this->calendar->dateRelativeToProduction(
                    $workspace,
                    $production->planned_for,
                    (int) $lockedTask->days_after_production,
                )->toDateString(),
                'scheduling_mode' => 'automatic',
            ]);

            return $lockedTask->fresh(['productionRun', 'employee']);
        }, attempts: 5);
    }
}
