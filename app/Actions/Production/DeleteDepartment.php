<?php

namespace App\Actions\Production;

use App\Models\Department;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProductionBenchAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeleteDepartment
{
    public function __construct(private readonly ProductionBenchAccess $access) {}

    public function handle(User $actor, Workspace $workspace, Department $department): void
    {
        $this->access->assertWritable($actor, $workspace);

        DB::transaction(function () use ($actor, $department, $workspace): void {
            $lockedWorkspace = Workspace::withoutGlobalScopes()->lockForUpdate()->findOrFail($workspace->id);
            $this->access->assertWritable($actor, $lockedWorkspace);
            $current = Department::query()->lockForUpdate()->find($department->id);

            if (! $current instanceof Department || (int) $current->workspace_id !== (int) $lockedWorkspace->id) {
                throw ValidationException::withMessages([
                    'department' => 'The department does not belong to this workspace.',
                ]);
            }

            if ($current->employees()->exists() || $current->productionTaskTypes()->exists() || $current->productionTasks()->exists()) {
                throw ValidationException::withMessages([
                    'department' => 'This department is in use. Deactivate it instead of deleting it.',
                ]);
            }

            $current->delete();
        }, attempts: 5);
    }
}
