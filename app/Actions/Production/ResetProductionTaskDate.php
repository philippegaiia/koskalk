<?php

namespace App\Actions\Production;

use App\Enums\ProductionRunStatus;
use App\Models\ProductionRun;
use App\Models\ProductionTask;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Production\ProductionEditingContext;
use App\Services\Production\ProductionMutationResult;
use App\Services\Production\ProductionWorkingCalendar;
use App\Services\ProductionBenchAccess;
use App\Services\ProductionMutationGuard;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class ResetProductionTaskDate
{
    public function __construct(private readonly ProductionMutationGuard $guard,
        private readonly ProductionBenchAccess $access,
        private readonly ProductionWorkingCalendar $calendar,
    ) {}

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

            return new ProductionMutationResult($lockedTask->fresh(['productionRun.tasks', 'employee']), ($before !== [$lockedTask->employee_id, $lockedTask->department_id, $lockedTask->scheduled_for?->toDateString(), $lockedTask->scheduling_mode, $lockedTask->completed_at?->toIso8601String(), $production->planned_for?->toDateString()]) ? [$parentId] : []);
        });
    }
}
