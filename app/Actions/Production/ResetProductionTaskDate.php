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
            throw ValidationException::withMessages(['task' => __('production_bench.production.validation.task_workspace_missing')]);
        }

        $this->access->assertWritable($actor, $workspace);

        return DB::transaction(function () use ($actor, $task): ProductionTask {
            $lockedTask = ProductionTask::query()->lockForUpdate()->findOrFail($task->id);
            $production = ProductionRun::query()->lockForUpdate()->find($lockedTask->production_run_id);

            if ($production === null) {
                throw ValidationException::withMessages(['task' => __('production_bench.production.validation.task_production_missing')]);
            }

            $workspace = Workspace::withoutGlobalScopes()->lockForUpdate()->find($production->workspace_id);

            if ($workspace === null || (int) $lockedTask->workspace_id !== (int) $workspace->id) {
                throw ValidationException::withMessages(['task' => __('production_bench.production.validation.task_workspace_mismatch')]);
            }

            $this->access->assertWritable($actor, $workspace);

            if (! in_array($production->status, [
                ProductionRunStatus::Draft,
                ProductionRunStatus::Scheduled,
                ProductionRunStatus::Reserved,
            ], true)) {
                throw ValidationException::withMessages(['task' => __('production_bench.production.validation.task_date_change_after_start')]);
            }

            if ($lockedTask->completed_at !== null) {
                throw ValidationException::withMessages(['task' => __('production_bench.production.validation.task_reset_reopen_required')]);
            }

            $anchorTaskId = ProductionTask::query()
                ->where('production_run_id', $production->id)
                ->where('days_after_production', 0)
                ->orderBy('id')
                ->value('id');

            if ((int) $anchorTaskId === (int) $lockedTask->id) {
                throw ValidationException::withMessages(['task' => __('production_bench.production.validation.task_anchor_reset_invalid')]);
            }

            if ($production->planned_for === null) {
                throw ValidationException::withMessages(['production' => __('production_bench.production.validation.task_reset_planned_date_required')]);
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
