<?php

namespace App\Policies;

use App\Models\ProductionBatch;
use App\Models\User;

class ProductionBatchPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ProductionBatch $productionBatch): bool
    {
        return $productionBatch->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, ProductionBatch $productionBatch): bool
    {
        return $productionBatch->user_id === $user->id;
    }

    public function delete(User $user, ProductionBatch $productionBatch): bool
    {
        return false;
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
