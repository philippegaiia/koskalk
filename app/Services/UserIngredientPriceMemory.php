<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserIngredientPrice;

class UserIngredientPriceMemory
{
    public function __construct(
        private readonly LiveCostingPricePropagationService $liveCostingPricePropagationService,
    ) {}

    public function remember(User $user, int $ingredientId, float $pricePerKg, ?string $currency = null, ?int $exceptCostingId = null): UserIngredientPrice
    {
        $currency ??= UserIngredientPrice::query()
            ->where('user_id', $user->id)
            ->where('ingredient_id', $ingredientId)
            ->value('currency') ?: $user->defaultCurrency();

        $price = round($pricePerKg, 4);

        $ingredientPrice = UserIngredientPrice::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'ingredient_id' => $ingredientId,
            ],
            [
                'price_per_kg' => $price,
                'currency' => $currency,
                'last_used_at' => now(),
            ],
        );

        $this->liveCostingPricePropagationService->ingredientPriceChanged($user, $ingredientId, $price, $exceptCostingId);

        return $ingredientPrice;
    }
}
