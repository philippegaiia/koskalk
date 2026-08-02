<?php

namespace App\Policies;

use App\Models\PackagingItem;
use App\Models\User;

class PackagingItemPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, PackagingItem $packagingItem): bool
    {
        return in_array($packagingItem->workspace_id, $user->accessibleWorkspaceIds(), true);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, PackagingItem $packagingItem): bool
    {
        return $this->view($user, $packagingItem);
    }

    public function delete(User $user, PackagingItem $packagingItem): bool
    {
        return $this->view($user, $packagingItem);
    }

    public function restore(User $user, PackagingItem $packagingItem): bool
    {
        return $this->update($user, $packagingItem);
    }

    public function forceDelete(User $user, PackagingItem $packagingItem): bool
    {
        return false;
    }
}
