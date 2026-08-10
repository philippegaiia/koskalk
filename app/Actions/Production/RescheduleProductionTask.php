<?php

namespace App\Actions\Production;

use App\Enums\ProductionRunStatus;
use App\Models\Employee;
use App\Models\ProductionRun;
use App\Models\ProductionTask;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Production\ProductionWorkingCalendar;
use App\Services\ProductionBenchAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RescheduleProductionTask
{
    public function __construct(
        private readonly ProductionBenchAccess $access,
        private readonly ProductionWorkingCalendar $calendar,
    ) {}

    public function handle(
        User $actor,
        ProductionTask $task,
        ?string $scheduledFor = null,
        ?Employee $employee = null,
        bool $clearEmployee = false,
    ): ProductionTask {
        if ($scheduledFor !== null) {
            $this->validateDate($scheduledFor);
        }

        $workspace = $task->workspace;

        if ($workspace === null) {
            throw ValidationException::withMessages(['task' => __('production_bench.production.validation.task_workspace_missing')]);
        }

        $this->access->assertWritable($actor, $workspace);

        return DB::transaction(function () use ($actor, $clearEmployee, $employee, $scheduledFor, $task): ProductionTask {
            $lockedTask = ProductionTask::query()->lockForUpdate()->findOrFail($task->id);
            $lockedProduction = ProductionRun::query()->lockForUpdate()->find($lockedTask->production_run_id);

            if ($lockedProduction === null) {
                throw ValidationException::withMessages(['task' => __('production_bench.production.validation.task_production_missing')]);
            }

            $lockedWorkspace = Workspace::withoutGlobalScopes()->lockForUpdate()->find($lockedProduction->workspace_id);

            if ($lockedWorkspace === null || (int) $lockedTask->workspace_id !== (int) $lockedWorkspace->id) {
                throw ValidationException::withMessages(['task' => __('production_bench.production.validation.task_workspace_mismatch')]);
            }

            $this->access->assertWritable($actor, $lockedWorkspace);

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

                return $lockedTask->fresh(['productionRun', 'employee']);
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

            return $lockedTask->fresh(['productionRun', 'employee']);
        }, attempts: 5);
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
