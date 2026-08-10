<?php

namespace App\Actions\Production;

use App\Enums\ProductionRunStatus;
use App\Models\ProductionRun;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Production\ProductionReadyDateService;
use App\Services\Production\ProductionWorkingCalendar;
use App\Services\ProductionBenchAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ScheduleProduction
{
    public function __construct(
        private readonly ProductionBenchAccess $access,
        private readonly GenerateProductionTasks $generateProductionTasks,
        private readonly ProductionWorkingCalendar $calendar,
        private readonly ProductionReadyDateService $readyDates,
    ) {}

    public function handle(User $actor, ProductionRun $production, string $plannedFor): ProductionRun
    {
        $workspace = $production->workspace;

        if ($workspace === null) {
            throw ValidationException::withMessages([
                'production' => 'The production workspace could not be found.',
            ]);
        }

        $this->access->assertWritable($actor, $workspace);

        if (! $this->isValidDate($plannedFor)) {
            throw ValidationException::withMessages([
                'planned_for' => 'The production date must use YYYY-MM-DD format.',
            ]);
        }

        if (! $this->calendar->isWorkingDate($workspace, $plannedFor)) {
            throw ValidationException::withMessages([
                'planned_for' => 'The production date must be a working day.',
            ]);
        }

        return DB::transaction(function () use ($actor, $production, $plannedFor): ProductionRun {
            $lockedProduction = ProductionRun::query()
                ->lockForUpdate()
                ->findOrFail($production->id);
            $lockedWorkspace = Workspace::withoutGlobalScopes()
                ->lockForUpdate()
                ->find($lockedProduction->workspace_id);

            if ($lockedWorkspace === null) {
                throw ValidationException::withMessages([
                    'production' => 'The production workspace could not be found.',
                ]);
            }

            $this->access->assertWritable($actor, $lockedWorkspace);

            if ($lockedProduction->status !== ProductionRunStatus::Draft) {
                throw ValidationException::withMessages([
                    'production' => 'Only draft productions can be planned.',
                ]);
            }

            $estimatedReadyOn = $lockedProduction->output_ready_delay_days === null
                ? null
                : $this->readyDates->estimatedReadyOn($plannedFor, (int) $lockedProduction->output_ready_delay_days);

            $lockedProduction->update([
                'status' => ProductionRunStatus::Scheduled,
                'planned_for' => $plannedFor,
                'estimated_ready_on' => $estimatedReadyOn,
            ]);

            return $this->generateProductionTasks->generateForLockedProduction(
                actor: $actor,
                lockedProduction: $lockedProduction,
                lockedWorkspace: $lockedWorkspace,
            );
        }, attempts: 5);
    }

    private function isValidDate(string $date): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return false;
        }

        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }
}
