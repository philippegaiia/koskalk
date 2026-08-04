<?php

namespace App\Actions\Production;

use App\Models\ProductionRun;
use App\Models\ProductionTask;
use App\Models\User;
use App\Models\Workspace;
use App\ProductionRunStatus;
use App\Services\Production\ProductionWorkingCalendar;
use App\Services\ProductionBenchAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RescheduleProduction
{
    public function __construct(
        private readonly ProductionBenchAccess $access,
        private readonly ProductionWorkingCalendar $calendar,
    ) {}

    public function handle(User $actor, ProductionRun $production, string $plannedFor): ProductionRun
    {
        $this->validateDate($plannedFor);
        $workspace = $production->workspace;

        if ($workspace === null) {
            throw ValidationException::withMessages(['production' => 'The production workspace could not be found.']);
        }

        $this->access->assertWritable($actor, $workspace);

        return DB::transaction(function () use ($actor, $plannedFor, $production): ProductionRun {
            $lockedProduction = ProductionRun::query()->lockForUpdate()->findOrFail($production->id);
            $lockedWorkspace = Workspace::withoutGlobalScopes()->lockForUpdate()->find($lockedProduction->workspace_id);

            if ($lockedWorkspace === null) {
                throw ValidationException::withMessages(['production' => 'The production workspace could not be found.']);
            }

            $this->access->assertWritable($actor, $lockedWorkspace);

            if (! in_array($lockedProduction->status, [
                ProductionRunStatus::Draft,
                ProductionRunStatus::Scheduled,
                ProductionRunStatus::Reserved,
            ], true)) {
                throw ValidationException::withMessages([
                    'production' => 'The production date cannot be changed after production starts.',
                ]);
            }

            $lockedProduction->update(['planned_for' => $plannedFor]);
            $tasks = ProductionTask::query()
                ->where('production_run_id', $lockedProduction->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $first = $tasks->first();

            if ($first instanceof ProductionTask) {
                if ($first->completed_at !== null) {
                    throw ValidationException::withMessages([
                        'production' => 'A production with a completed anchor task cannot be rescheduled.',
                    ]);
                }

                $first->update([
                    'days_after_production' => 0,
                    'scheduled_for' => $plannedFor,
                    'scheduling_mode' => 'automatic',
                ]);

                foreach ($tasks->skip(1) as $task) {
                    if ($task->completed_at !== null || $task->scheduling_mode !== 'automatic') {
                        continue;
                    }

                    $task->update([
                        'scheduled_for' => $this->calendar->dateAfterProduction(
                            $lockedWorkspace,
                            $plannedFor,
                            (int) $task->days_after_production,
                        )->toDateString(),
                    ]);
                }
            }

            return $lockedProduction->fresh(['requirements', 'tasks']);
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
            throw ValidationException::withMessages(['planned_for' => 'The production date must use YYYY-MM-DD format.']);
        }
    }
}
