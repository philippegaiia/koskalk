<?php

namespace App\Policies;

use App\Models\IngredientEnrichmentBatch;
use App\Models\User;

class IngredientEnrichmentBatchPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_admin;
    }

    public function view(User $user, IngredientEnrichmentBatch $batch): bool
    {
        return $user->is_admin;
    }

    public function create(User $user): bool
    {
        return $user->is_admin;
    }

    public function update(User $user, IngredientEnrichmentBatch $batch): bool
    {
        return $user->is_admin;
    }

    public function approve(User $user, IngredientEnrichmentBatch $batch): bool
    {
        return $user->is_admin;
    }

    public function apply(User $user, IngredientEnrichmentBatch $batch): bool
    {
        return $user->is_admin;
    }

    public function retry(User $user, IngredientEnrichmentBatch $batch): bool
    {
        return $user->is_admin;
    }

    public function cancel(User $user, IngredientEnrichmentBatch $batch): bool
    {
        return $user->is_admin;
    }

    public function delete(User $user, IngredientEnrichmentBatch $batch): bool
    {
        return $user->is_admin;
    }
}
