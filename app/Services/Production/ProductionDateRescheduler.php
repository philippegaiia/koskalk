<?php

namespace App\Services\Production;

use App\Enums\ProductionRunStatus;
use App\Enums\StockReservationStatus;
use App\Models\ProductionRun;
use App\Models\ProductionTask;
use App\Models\StockReservation;
use App\Models\Workspace;
use Illuminate\Validation\ValidationException;

class ProductionDateRescheduler
{
    public function __construct(
        private readonly ProductionWorkingCalendar $calendar,
        private readonly ProductionReadyDateService $readyDates,
    ) {}

    public function rescheduleLocked(Workspace $workspace, ProductionRun $production, string $plannedFor): void
    {
        if (! $this->calendar->isWorkingDate($workspace, $plannedFor)) {
            throw ValidationException::withMessages([
                'planned_for' => __('production_bench.production.validation.planned_date_working_day'),
            ]);
        }

        $tasks = ProductionTask::query()
            ->where('production_run_id', $production->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $anchor = $tasks->firstWhere('days_after_production', 0);

        if ($anchor instanceof ProductionTask && $anchor->completed_at !== null) {
            throw ValidationException::withMessages([
                'production' => __('production_bench.production.validation.reschedule_completed_anchor'),
            ]);
        }

        StockReservation::query()
            ->where('production_run_id', $production->id)
            ->where('status', StockReservationStatus::Active)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->each(fn (StockReservation $reservation): bool => $reservation->update([
                'status' => StockReservationStatus::Released,
                'released_at' => now(),
            ]));

        if ($production->status === ProductionRunStatus::Reserved) {
            $production->update(['status' => ProductionRunStatus::Scheduled]);
        }

        $production->update([
            'planned_for' => $plannedFor,
            'estimated_ready_on' => $production->output_ready_delay_days === null
                ? null
                : $this->readyDates->estimatedReadyOn($plannedFor, (int) $production->output_ready_delay_days),
        ]);

        foreach ($tasks as $task) {
            if ($task->completed_at !== null || $task->scheduling_mode !== 'automatic') {
                continue;
            }

            $task->update([
                'scheduled_for' => $this->calendar->dateRelativeToProduction(
                    $workspace,
                    $plannedFor,
                    (int) $task->days_after_production,
                )->toDateString(),
            ]);
        }
    }
}
