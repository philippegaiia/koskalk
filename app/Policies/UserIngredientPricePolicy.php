<?php

namespace App\Policies;

use App\Models\User;
use App\Models\UserIngredientPrice;

class UserIngredientPricePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, UserIngredientPrice $userIngredientPrice): bool
    {
        return $userIngredientPrice->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, UserIngredientPrice $userIngredientPrice): bool
    {
        return $this->view($user, $userIngredientPrice);
    }

    public function delete(User $user, UserIngredientPrice $userIngredientPrice): bool
    {
        return $this->view($user, $userIngredientPrice);
    }

    public function restore(User $user, UserIngredientPrice $userIngredientPrice): bool
    {
        return $this->update($user, $userIngredientPrice);
    }

    public function forceDelete(User $user, UserIngredientPrice $userIngredientPrice): bool
    {
        return false;
    }
}
