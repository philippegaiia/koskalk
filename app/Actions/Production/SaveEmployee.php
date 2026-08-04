<?php

namespace App\Actions\Production;

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
    ): Employee {
        $this->access->assertWritable($actor, $workspace);
        $firstName = trim($firstName);
        $lastName = trim($lastName);

        if ($firstName === '' || mb_strlen($firstName) > 80) {
            throw ValidationException::withMessages(['first_name' => 'Enter a first name.']);
        }

        if ($lastName === '' || mb_strlen($lastName) > 120) {
            throw ValidationException::withMessages(['last_name' => 'Enter a last name.']);
        }

        return DB::transaction(function () use ($actor, $employee, $firstName, $isActive, $lastName, $workspace): Employee {
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
                'is_active' => $isActive,
            ];

            if ($current instanceof Employee) {
                $current->update($values);

                return $current->fresh();
            }

            return Employee::query()->create($values);
        }, attempts: 5);
    }
}
