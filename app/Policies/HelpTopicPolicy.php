<?php

namespace App\Policies;

use App\Models\HelpTopic;
use App\Models\User;

class HelpTopicPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_admin;
    }

    public function create(User $user): bool
    {
        return $user->is_admin;
    }

    public function view(User $user, HelpTopic $helpTopic): bool
    {
        return $user->is_admin;
    }

    public function update(User $user, HelpTopic $helpTopic): bool
    {
        return $user->is_admin;
    }

    public function delete(User $user, HelpTopic $helpTopic): bool
    {
        return false;
    }

    public function restore(User $user, HelpTopic $helpTopic): bool
    {
        return $user->is_admin;
    }

    public function forceDelete(User $user, HelpTopic $helpTopic): bool
    {
        return false;
    }

    public function publish(User $user, HelpTopic $helpTopic): bool
    {
        return $user->is_admin;
    }

    public function archive(User $user, HelpTopic $helpTopic): bool
    {
        return $user->is_admin;
    }

    public function translate(User $user, HelpTopic $helpTopic): bool
    {
        return $user->is_admin;
    }

    public function import(User $user): bool
    {
        return $user->is_admin;
    }

    public function export(User $user): bool
    {
        return $user->is_admin;
    }
}
