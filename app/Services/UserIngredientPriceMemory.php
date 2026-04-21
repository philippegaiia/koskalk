<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserIngredientPrice;

class UserIngredientPriceMemory
{
    public function remember(User $user, int $ingredientId, float $pricePerKg, ?string $currency = null): UserIngredientPrice
    {
        $currency ??= UserIngredientPrice::query()
            ->where('user_id', $user->id)
            ->where('ingredient_id', $ingredientId)
            ->value('currency') ?: $user->defaultCurrency();

        return UserIngredientPrice::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'ingredient_id' => $ingredientId,
            ],
            [
                'price_per_kg' => round($pricePerKg, 4),
                'currency' => $currency,
                'last_used_at' => now(),
            ],
        );
    }
}
