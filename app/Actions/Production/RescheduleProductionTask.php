<?php

namespace App\Actions\Production;

use App\Enums\ProductionRunStatus;
use App\Models\Employee;
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

class RescheduleProductionTask
{
    public function __construct(private readonly ProductionMutationGuard $guard,
        private readonly ProductionBenchAccess $access,
        private readonly ProductionWorkingCalendar $calendar,
    ) {}

    public function handle(
        User $actor,
        ProductionTask $task,
        ?string $scheduledFor = null,
        ?Employee $employee = null,
        bool $clearEmployee = false,
        ?ProductionEditingContext $editing = null,
    ): ProductionTask {
        if ($scheduledFor !== null) {
            $this->validateDate($scheduledFor);
        }

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

        return $this->guard->run($actor, [$parentId], $editing, function (User $actor, Workspace $workspace, Collection $productions) use ($clearEmployee, $employee, $scheduledFor, $task, $parentId): ProductionMutationResult {
            $production = $productions[$parentId];
            $lockedProduction = $production;
            $lockedWorkspace = $workspace;
            $lockedTask = ProductionTask::query()->where('workspace_id', $workspace->id)->where('production_run_id', $parentId)->lockForUpdate()->find($task->id);
            if ($lockedTask === null) {
                throw ValidationException::withMessages(['task' => __('production_bench.production.validation.task_production_missing')]);
            }
            $before = [$lockedTask->employee_id, $lockedTask->department_id, $lockedTask->scheduled_for?->toDateString(), $lockedTask->scheduling_mode, $lockedTask->completed_at?->toIso8601String(), $production->planned_for?->toDateString()];
            $datesBefore = $production->tasks()->orderBy('id')->pluck('scheduled_for', 'id')->all();

            if (! in_array($lockedProduction->status, [
                ProductionRunStatus::Draft,
                ProductionRunStatus::Scheduled,
                ProductionRunStatus::Reserved,
            ], true)) {
                throw ValidationException::withMessages([
                    'task' => __('production_bench.production.validation.task_date_change_after_start'),
                ]);
            }

            if ($clearEmployee) {
                $lockedTask->employee_id = null;
            } elseif ($employee instanceof Employee) {
                $candidate = Employee::query()
                    ->where('workspace_id', $lockedWorkspace->id)
                    ->lockForUpdate()
                    ->find($employee->id);

                if ($candidate === null || ! $candidate->is_active) {
                    throw ValidationException::withMessages([
                        'employee' => __('production_bench.production.validation.task_employee_invalid'),
                    ]);
                }

                $lockedTask->employee_id = $candidate->id;
            }

            if ($scheduledFor === null) {
                $lockedTask->save();

                return new ProductionMutationResult($lockedTask->fresh(['productionRun.tasks', 'employee']), ($before !== [$lockedTask->employee_id, $lockedTask->department_id, $lockedTask->scheduled_for?->toDateString(), $lockedTask->scheduling_mode, $lockedTask->completed_at?->toIso8601String(), $production->planned_for?->toDateString()] || $datesBefore !== $production->tasks()->orderBy('id')->pluck('scheduled_for', 'id')->all()) ? [$parentId] : []);
            }

            $anchorTaskId = ProductionTask::query()
                ->where('production_run_id', $lockedProduction->id)
                ->where('days_after_production', 0)
                ->orderBy('id')
                ->value('id');

            if ((int) $anchorTaskId === (int) $lockedTask->id) {
                if ($lockedTask->completed_at !== null) {
                    throw ValidationException::withMessages([
                        'task' => __('production_bench.production.validation.task_anchor_reschedule_completed'),
                    ]);
                }

                if (! $this->calendar->isWorkingDate($lockedWorkspace, $scheduledFor)) {
                    throw ValidationException::withMessages([
                        'scheduled_for' => __('production_bench.production.validation.planned_date_working_day'),
                    ]);
                }

                $lockedProduction->update(['planned_for' => $scheduledFor]);
                $lockedTask->scheduled_for = $scheduledFor;
                $lockedTask->scheduling_mode = 'automatic';
                $lockedTask->save();

                $laterTasks = ProductionTask::query()
                    ->where('production_run_id', $lockedProduction->id)
                    ->where('id', '!=', $lockedTask->id)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                foreach ($laterTasks as $laterTask) {
                    if ($laterTask->completed_at !== null || $laterTask->scheduling_mode !== 'automatic') {
                        continue;
                    }

                    $laterTask->update([
                        'scheduled_for' => $this->calendar->dateRelativeToProduction(
                            $lockedWorkspace,
                            $scheduledFor,
                            (int) $laterTask->days_after_production,
                        )->toDateString(),
                    ]);
                }
            } else {
                $lockedTask->scheduled_for = $scheduledFor;
                $lockedTask->scheduling_mode = 'custom';
                $lockedTask->save();
            }

            return new ProductionMutationResult($lockedTask->fresh(['productionRun.tasks', 'employee']), ($before !== [$lockedTask->employee_id, $lockedTask->department_id, $lockedTask->scheduled_for?->toDateString(), $lockedTask->scheduling_mode, $lockedTask->completed_at?->toIso8601String(), $production->planned_for?->toDateString()] || $datesBefore !== $production->tasks()->orderBy('id')->pluck('scheduled_for', 'id')->all()) ? [$parentId] : []);
        });
    }

    private function validateDate(string $date): void
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $errors = \DateTimeImmutable::getLastErrors();

        if (
            preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1
            || $parsed === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $parsed->format('Y-m-d') !== $date
        ) {
            throw ValidationException::withMessages(['scheduled_for' => __('production_bench.production.validation.task_date_format')]);
        }
    }
}
