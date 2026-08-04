<?php

namespace App\Actions\Production;

use App\Models\User;
use App\Models\Workspace;
use App\Services\ProductionBenchAccess;
use Illuminate\Support\Facades\DB;

class UpdateProductionWorkingCalendar
{
    public function __construct(private readonly ProductionBenchAccess $access) {}

    public function handle(User $actor, Workspace $workspace, bool $worksOnWeekends): Workspace
    {
        $this->access->assertWritable($actor, $workspace);

        return DB::transaction(function () use ($actor, $workspace, $worksOnWeekends): Workspace {
            $lockedWorkspace = Workspace::withoutGlobalScopes()->lockForUpdate()->findOrFail($workspace->id);
            $this->access->assertWritable($actor, $lockedWorkspace);
            $lockedWorkspace->update(['production_works_on_weekends' => $worksOnWeekends]);

            return $lockedWorkspace->fresh();
        }, attempts: 5);
    }
}
