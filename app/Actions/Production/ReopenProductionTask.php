<?php

namespace App\Actions\Production;

use App\Enums\ProductionRunStatus;
use App\Models\ProductionRun;
use App\Models\ProductionTask;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProductionBenchAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReopenProductionTask
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
                ProductionRunStatus::Completed,
            ], true)) {
                throw ValidationException::withMessages(['task' => 'This production task cannot be reopened.']);
            }

            if ($production->status === ProductionRunStatus::Completed
                || $production->outputLot()->where('status', 'released')->exists()) {
                throw ValidationException::withMessages([
                    'task' => 'Completed production tasks cannot be reopened after production completion or output release.',
                ]);
            }

            if ($lockedTask->completed_at === null) {
                throw ValidationException::withMessages(['task' => 'This production task is not complete.']);
            }

            $lockedTask->update(['completed_at' => null]);

            return $lockedTask->fresh(['productionRun', 'employee']);
        }, attempts: 5);
    }
}
