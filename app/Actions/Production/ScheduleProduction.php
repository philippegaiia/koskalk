<?php

namespace App\Actions\Production;

use App\Models\ProductionRun;
use App\Models\User;
use App\Models\Workspace;
use App\ProductionRunStatus;
use App\Services\Production\ProductionWorkingCalendar;
use App\Services\ProductionBenchAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ScheduleProduction
{
    public function __construct(
        private readonly ProductionBenchAccess $access,
        private readonly GenerateProductionTasks $generateProductionTasks,
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

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $plannedFor)) {
            throw ValidationException::withMessages([
                'planned_for' => 'The production date must use YYYY-MM-DD format.',
            ]);
        }

        if (! app(ProductionWorkingCalendar::class)->isWorkingDate($workspace, $plannedFor)) {
            throw ValidationException::withMessages([
                'planned_for' => 'The production date must be a working day.',
            ]);
        }

        $scheduled = DB::transaction(function () use ($actor, $production, $plannedFor): ProductionRun {
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

            $lockedProduction->update([
                'status' => ProductionRunStatus::Scheduled,
                'planned_for' => $plannedFor,
            ]);

            return $lockedProduction->fresh('requirements');
        }, attempts: 5);

        return $this->generateProductionTasks->handle($actor, $scheduled);
    }
}
