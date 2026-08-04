<?php

namespace App\Actions\Production;

use App\Models\ProductionRun;
use App\Models\User;
use App\Models\Workspace;
use App\ProductionRunStatus;
use App\Services\ProductionBenchAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ScheduleProduction
{
    public function __construct(private readonly ProductionBenchAccess $access) {}

    public function handle(User $actor, ProductionRun $production): ProductionRun
    {
        $workspace = $production->workspace;

        if ($workspace === null) {
            throw ValidationException::withMessages([
                'production' => 'The production workspace could not be found.',
            ]);
        }

        $this->access->assertWritable($actor, $workspace);

        return DB::transaction(function () use ($actor, $production): ProductionRun {
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

            $lockedProduction->update(['status' => ProductionRunStatus::Scheduled]);

            return $lockedProduction->fresh('requirements');
        }, attempts: 5);
    }
}
