<?php

namespace App\Policies;

use App\Models\ProductionBatch;
use App\Models\Recipe;
use App\Models\User;
use App\Services\WorkspaceAuthorization;

class ProductionBatchPolicy
{
    public function __construct(private readonly WorkspaceAuthorization $authorization) {}

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ProductionBatch $productionBatch): bool
    {
        return $productionBatch->workspace_id !== null
            ? $this->authorization->canView($user, $productionBatch->workspace_id)
            : $productionBatch->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->can('create', Recipe::class);
    }

    public function update(User $user, ProductionBatch $productionBatch): bool
    {
        return $productionBatch->workspace_id !== null
            ? $this->authorization->canEdit($user, $productionBatch->workspace_id)
            : $productionBatch->user_id === $user->id;
    }

    public function delete(User $user, ProductionBatch $productionBatch): bool
    {
        return $productionBatch->workspace_id !== null
            ? $this->authorization->canManage($user, $productionBatch->workspace_id)
            : $productionBatch->user_id === $user->id;
    }

    public function restore(User $user, ProductionBatch $productionBatch): bool
    {
        return false;
    }

    public function forceDelete(User $user, ProductionBatch $productionBatch): bool
    {
        return false;
    }
}
