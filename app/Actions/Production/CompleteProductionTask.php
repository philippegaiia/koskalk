<?php

namespace App\Actions\Production;

use App\Models\ProductionRun;
use App\Models\ProductionTask;
use App\Models\User;
use App\Models\Workspace;
use App\ProductionRunStatus;
use App\Services\ProductionBenchAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CompleteProductionTask
{
    public function __construct(private readonly ProductionBenchAccess $access) {}

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
            $workspace = $production === null
                ? null
                : Workspace::withoutGlobalScopes()->lockForUpdate()->find($production->workspace_id);

            if ($production === null || $workspace === null || (int) $lockedTask->workspace_id !== (int) $workspace->id) {
                throw ValidationException::withMessages(['task' => 'The task does not belong to this workspace.']);
            }

            $this->access->assertWritable($actor, $workspace);

            if (! in_array($production->status, [
                ProductionRunStatus::Draft,
                ProductionRunStatus::Scheduled,
                ProductionRunStatus::Reserved,
                ProductionRunStatus::InProduction,
            ], true)) {
                throw ValidationException::withMessages(['task' => 'This production task cannot be completed.']);
            }

            if ($lockedTask->completed_at !== null) {
                throw ValidationException::withMessages(['task' => 'This production task is already complete.']);
            }

            $lockedTask->update(['completed_at' => now()]);

            return $lockedTask->fresh(['productionRun', 'employee']);
        }, attempts: 5);
    }
}
