<?php

namespace App\Actions\Production;

use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProductionBenchAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveEmployee
{
    public function __construct(private readonly ProductionBenchAccess $access) {}

    public function handle(
        User $actor,
        Workspace $workspace,
        string $firstName,
        string $lastName,
        bool $isActive = true,
        ?Employee $employee = null,
        ?string $title = null,
        array $departmentIds = [],
    ): Employee {
        $this->access->assertWritable($actor, $workspace);
        $firstName = trim($firstName);
        $lastName = trim($lastName);
        $title = $title !== null ? trim($title) : null;

        if ($firstName === '' || mb_strlen($firstName) > 80) {
            throw ValidationException::withMessages(['first_name' => 'Enter a first name.']);
        }

        if ($lastName === '' || mb_strlen($lastName) > 120) {
            throw ValidationException::withMessages(['last_name' => 'Enter a last name.']);
        }

        if ($title !== null && mb_strlen($title) > 120) {
            throw ValidationException::withMessages(['title' => 'Enter a title no longer than 120 characters.']);
        }

        $departmentIds = array_values(array_unique(array_map('intval', $departmentIds)));

        return DB::transaction(function () use ($actor, $departmentIds, $employee, $firstName, $isActive, $lastName, $title, $workspace): Employee {
            $lockedWorkspace = Workspace::withoutGlobalScopes()->lockForUpdate()->findOrFail($workspace->id);
            $this->access->assertWritable($actor, $lockedWorkspace);
            $current = null;

            if ($employee instanceof Employee) {
                $current = Employee::query()->lockForUpdate()->find($employee->id);

                if (! $current instanceof Employee || (int) $current->workspace_id !== (int) $lockedWorkspace->id) {
                    throw ValidationException::withMessages([
                        'employee' => 'The employee does not belong to this workspace.',
                    ]);
                }
            }

            $values = [
                'workspace_id' => $lockedWorkspace->id,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'title' => $title !== '' ? $title : null,
                'is_active' => $isActive,
            ];

            if ($current instanceof Employee) {
                $current->update($values);

                $this->syncDepartments($current, $lockedWorkspace->id, $departmentIds);

                return $current->fresh();
            }

            $created = Employee::query()->create($values);
            $this->syncDepartments($created, $lockedWorkspace->id, $departmentIds);

            return $created->fresh();
        }, attempts: 5);
    }

    /** @param list<int> $departmentIds */
    private function syncDepartments(Employee $employee, int $workspaceId, array $departmentIds): void
    {
        if ($departmentIds !== []) {
            $departments = Department::query()
                ->where('workspace_id', $workspaceId)
                ->whereIn('id', $departmentIds)
                ->where('is_active', true)
                ->get();

            if ($departments->count() !== count($departmentIds)) {
                throw ValidationException::withMessages([
                    'departments' => 'Select only active departments from this workspace.',
                ]);
            }
        }

        $employee->departments()->sync($departmentIds);
    }
}
