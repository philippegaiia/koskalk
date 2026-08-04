<?php

namespace App\Policies;

use App\Models\ProductionTask;
use App\Models\User;
use App\Models\Workspace;
use App\Policies\Concerns\HandlesWorkspaceAuthorization;
use App\Services\ProductionBenchAccess;

class ProductionTaskPolicy
{
    use HandlesWorkspaceAuthorization;

    public function __construct(private readonly ProductionBenchAccess $productionBenchAccess) {}

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ProductionTask $task): bool
    {
        $workspace = $task->workspace;

        return $workspace instanceof Workspace && $this->canAccessWorkspace($user, $workspace);
    }

    public function update(User $user, ProductionTask $task): bool
    {
        $workspace = $task->workspace;

        return $workspace instanceof Workspace
            && $this->canEditWorkspaceRecords($user, $workspace->id)
            && $this->productionBenchAccess->isActive($workspace);
    }

    public function delete(User $user, ProductionTask $task): bool
    {
        return false;
    }

    public function restore(User $user, ProductionTask $task): bool
    {
        return false;
    }

    public function forceDelete(User $user, ProductionTask $task): bool
    {
        return false;
    }
}
