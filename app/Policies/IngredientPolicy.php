<?php

namespace App\Policies;

use App\Enums\OwnerType;
use App\Enums\Visibility;
use App\Models\Ingredient;
use App\Models\User;
use App\Models\Workspace;
use App\Policies\Concerns\HandlesWorkspaceAuthorization;

class IngredientPolicy
{
    use HandlesWorkspaceAuthorization;

    public function createInWorkspace(User $user, ?Workspace $workspace): bool
    {
        return $workspace instanceof Workspace
            ? $this->canEditWorkspaceRecords($user, $workspace->id)
            : ! $user->is_admin
                && $user->active_workspace_id === null
                && $user->accessibleWorkspaceIds() === [];
    }

    public function duplicateIntoWorkspace(User $user, Ingredient $ingredient, ?Workspace $workspace): bool
    {
        if (! $ingredient->is_active) {
            return false;
        }

        $isPlatformIngredient = $ingredient->owner_type === null
            && $ingredient->owner_id === null
            && $ingredient->workspace_id === null;
        $isOwnedByUser = $ingredient->owner_type === OwnerType::User
            && (int) $ingredient->owner_id === (int) $user->id;
        $isOwnedByDestinationWorkspace = $workspace instanceof Workspace
            && $ingredient->owner_type === OwnerType::Workspace
            && (int) $ingredient->owner_id === (int) $workspace->id;

        if (! $isPlatformIngredient && ! $isOwnedByUser && ! $isOwnedByDestinationWorkspace) {
            return false;
        }

        return $workspace instanceof Workspace
            ? $this->canEditWorkspaceRecords($user, $workspace->id)
            : ! $user->is_admin
                && $user->active_workspace_id === null
                && $user->accessibleWorkspaceIds() === [];
    }

    public function delete(User $user, Ingredient $ingredient): bool
    {
        if ($ingredient->visibility !== Visibility::Private) {
            return false;
        }

        return $ingredient->workspace_id === null
            ? $ingredient->isOwnedBy($user)
            : $this->canDeleteWorkspaceRecords($user, $ingredient->workspace_id);
    }

    public function editWorkspaceIngredient(User $user, Ingredient $ingredient): bool
    {
        $isPlatformIngredient = $ingredient->owner_type === null
            && $ingredient->owner_id === null
            && $ingredient->workspace_id === null;

        return ! $isPlatformIngredient && $ingredient->isEditableBy($user);
    }
}
