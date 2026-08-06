<?php

namespace App\Policies;

use App\Models\Department;
use App\Models\User;
use App\Models\Workspace;
use App\Policies\Concerns\HandlesWorkspaceAuthorization;
use App\Services\ProductionBenchAccess;

class DepartmentPolicy
{
    use HandlesWorkspaceAuthorization;

    public function __construct(private readonly ProductionBenchAccess $productionBenchAccess) {}

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Department $department): bool
    {
        $workspace = $department->workspace;

        return $workspace instanceof Workspace && $this->canAccessWorkspace($user, $workspace);
    }

    public function create(User $user, Workspace $workspace): bool
    {
        return $this->canEditWorkspaceRecords($user, $workspace->id)
            && $this->productionBenchAccess->isActive($workspace);
    }

    public function update(User $user, Department $department): bool
    {
        $workspace = $department->workspace;

        return $workspace instanceof Workspace
            && $this->canEditWorkspaceRecords($user, $workspace->id)
            && $this->productionBenchAccess->isActive($workspace);
    }

    public function delete(User $user, Department $department): bool
    {
        $workspace = $department->workspace;

        return $workspace instanceof Workspace
            && $this->canDeleteWorkspaceRecords($user, $workspace->id)
            && $this->productionBenchAccess->isActive($workspace);
    }

    public function restore(User $user, Department $department): bool
    {
        return false;
    }

    public function forceDelete(User $user, Department $department): bool
    {
        return false;
    }
}
