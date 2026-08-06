<?php

namespace App\Actions\Production;

use App\Models\Employee;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProductionBenchAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeleteEmployee
{
    public function __construct(private readonly ProductionBenchAccess $access) {}

    public function handle(User $actor, Workspace $workspace, Employee $employee): void
    {
        $this->access->assertWritable($actor, $workspace);

        DB::transaction(function () use ($actor, $employee, $workspace): void {
            $lockedWorkspace = Workspace::withoutGlobalScopes()->lockForUpdate()->findOrFail($workspace->id);
            $this->access->assertWritable($actor, $lockedWorkspace);
            $current = Employee::query()->lockForUpdate()->find($employee->id);

            if (! $current instanceof Employee || (int) $current->workspace_id !== (int) $lockedWorkspace->id) {
                throw ValidationException::withMessages([
                    'employee' => 'The employee does not belong to this workspace.',
                ]);
            }

            if ($current->productionTasks()->exists()) {
                throw ValidationException::withMessages([
                    'employee' => 'This employee is assigned to production tasks. Deactivate them instead of deleting them.',
                ]);
            }

            $current->departments()->detach();
            $current->delete();
        }, attempts: 5);
    }
}
