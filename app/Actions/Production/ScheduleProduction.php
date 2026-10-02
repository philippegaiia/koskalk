<?php

namespace App\Actions\Production;

use App\Enums\ProductionRunStatus;
use App\Models\ProductionRun;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Production\ProductionEditingContext;
use App\Services\Production\ProductionMutationResult;
use App\Services\Production\ProductionMutationScope;
use App\Services\Production\ProductionReadyDateService;
use App\Services\Production\ProductionWorkingCalendar;
use App\Services\ProductionBenchAccess;
use App\Services\ProductionMutationGuard;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class ScheduleProduction
{
    public function __construct(private readonly ProductionMutationGuard $guard,
        private readonly ProductionBenchAccess $access,
        private readonly GenerateProductionTasks $generateProductionTasks,
        private readonly ProductionWorkingCalendar $calendar,
        private readonly ProductionReadyDateService $readyDates,
    ) {}

    public function handle(User $actor, ProductionRun $production, string $plannedFor,
        ?ProductionEditingContext $editing = null,
    ): ProductionRun {
        $workspace = $production->workspace;

        if ($workspace === null) {
            throw ValidationException::withMessages([
                'production' => __('production_bench.production.workspace_missing'),
            ]);
        }

        $this->access->assertWritable($actor, $workspace);

        if (! $this->isValidDate($plannedFor)) {
            throw ValidationException::withMessages([
                'planned_for' => __('production_bench.production.validation.planned_date_format'),
            ]);
        }

        return $this->guard->run($actor, [$production->id], $editing, function (User $actor, Workspace $lockedWorkspace, Collection $productions, ProductionMutationScope $scope) use ($production, $plannedFor): ProductionMutationResult {

            if ($lockedWorkspace === null) {
                throw ValidationException::withMessages([
                    'production' => __('production_bench.production.workspace_missing'),
                ]);
            }

            $this->access->assertWritable($actor, $lockedWorkspace);
            $lockedProduction = $productions[$production->id];

            $this->calendar->refresh($lockedWorkspace);
            if (! $this->calendar->isWorkingDate($lockedWorkspace, $plannedFor)) {
                throw ValidationException::withMessages([
                    'planned_for' => __('production_bench.production.validation.planned_date_working_day'),
                ]);
            }

            if ($lockedProduction->status !== ProductionRunStatus::Draft) {
                throw ValidationException::withMessages([
                    'production' => __('production_bench.production.validation.schedule_draft_only'),
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

            $result = $this->generateProductionTasks->generateForLockedProduction(
                actor: $actor,
                lockedProduction: $lockedProduction,
                lockedWorkspace: $lockedWorkspace,
                scope: $scope,
            );

            return new ProductionMutationResult($result, [$lockedProduction->id]);
        });
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
