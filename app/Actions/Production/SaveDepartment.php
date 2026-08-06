<?php

namespace App\Actions\Production;

use App\Models\Department;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProductionBenchAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveDepartment
{
    public function __construct(private readonly ProductionBenchAccess $access) {}

    public function handle(
        User $actor,
        Workspace $workspace,
        string $name,
        bool $isActive = true,
        ?Department $department = null,
    ): Department {
        $this->access->assertWritable($actor, $workspace);
        $name = $this->normalizeDisplayName($name);

        if ($name === '' || mb_strlen($name) > 120) {
            throw ValidationException::withMessages(['name' => 'Enter a department name.']);
        }

        $normalizedName = mb_strtolower($name);

        return DB::transaction(function () use ($actor, $department, $isActive, $name, $normalizedName, $workspace): Department {
            $lockedWorkspace = Workspace::withoutGlobalScopes()->lockForUpdate()->findOrFail($workspace->id);
            $this->access->assertWritable($actor, $lockedWorkspace);

            $current = null;

            if ($department instanceof Department) {
                $current = Department::query()->lockForUpdate()->find($department->id);

                if (! $current instanceof Department || (int) $current->workspace_id !== (int) $lockedWorkspace->id) {
                    throw ValidationException::withMessages([
                        'department' => 'The department does not belong to this workspace.',
                    ]);
                }
            }

            $duplicate = Department::query()
                ->where('workspace_id', $lockedWorkspace->id)
                ->where('normalized_name', $normalizedName)
                ->when($current instanceof Department, fn ($query) => $query->whereKeyNot($current->id))
                ->exists();

            if ($duplicate) {
                throw ValidationException::withMessages([
                    'name' => 'A department with this name already exists.',
                ]);
            }

            $values = [
                'workspace_id' => $lockedWorkspace->id,
                'name' => $name,
                'normalized_name' => $normalizedName,
                'is_active' => $isActive,
            ];

            if ($current instanceof Department) {
                $current->update($values);

                return $current->fresh();
            }

            return Department::query()->create($values);
        }, attempts: 5);
    }

    private function normalizeDisplayName(string $name): string
    {
        return preg_replace('/\s+/', ' ', trim($name)) ?? trim($name);
    }
}
