<?php

namespace App\Actions\Production;

use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProductionBenchAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SyncEmployeeDepartments
{
    public function __construct(private readonly ProductionBenchAccess $access) {}

    /** @param list<int|string> $departmentIds */
    public function handle(
        User $actor,
        Workspace $workspace,
        Employee $employee,
        array $departmentIds,
    ): Employee {
        $this->access->assertWritable($actor, $workspace);
        $departmentIds = array_values(array_unique(array_map('intval', $departmentIds)));

        return DB::transaction(function () use ($actor, $departmentIds, $employee, $workspace): Employee {
            $lockedWorkspace = Workspace::withoutGlobalScopes()->lockForUpdate()->findOrFail($workspace->id);
            $this->access->assertWritable($actor, $lockedWorkspace);
            $current = Employee::query()->lockForUpdate()->find($employee->id);

            if (! $current instanceof Employee || (int) $current->workspace_id !== (int) $lockedWorkspace->id) {
                throw ValidationException::withMessages([
                    'employee' => 'The employee does not belong to this workspace.',
                ]);
            }

            if ($departmentIds !== []) {
                $departments = Department::query()
                    ->where('workspace_id', $lockedWorkspace->id)
                    ->whereIn('id', $departmentIds)
                    ->where('is_active', true)
                    ->get();

                if ($departments->count() !== count($departmentIds)) {
                    throw ValidationException::withMessages([
                        'departments' => 'Select only active departments from this workspace.',
                    ]);
                }
            }

            $current->departments()->sync($departmentIds);

            return $current->fresh('departments');
        }, attempts: 5);
    }
}
