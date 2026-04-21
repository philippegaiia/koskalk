<?php

namespace App\Policies;

use App\Models\User;
use App\Models\UserPackagingItem;

class UserPackagingItemPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, UserPackagingItem $userPackagingItem): bool
    {
        return $userPackagingItem->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, UserPackagingItem $userPackagingItem): bool
    {
        return $this->view($user, $userPackagingItem);
    }

    public function delete(User $user, UserPackagingItem $userPackagingItem): bool
    {
        return $this->view($user, $userPackagingItem);
    }

    public function restore(User $user, UserPackagingItem $userPackagingItem): bool
    {
        return $this->update($user, $userPackagingItem);
    }

    public function forceDelete(User $user, UserPackagingItem $userPackagingItem): bool
    {
        return false;
    }
}
