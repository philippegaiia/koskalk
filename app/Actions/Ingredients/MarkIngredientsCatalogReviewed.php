<?php

namespace App\Actions\Ingredients;

use App\Models\Ingredient;
use App\Models\User;
use App\Services\IngredientCatalogReviewService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

class MarkIngredientsCatalogReviewed
{
    public function __construct(
        private readonly IngredientCatalogReviewService $reviews,
    ) {}

    /**
     * @param  Collection<int, Ingredient>  $ingredients
     * @return int The number of ingredients newly signed off
     */
    public function handle(User $actor, Collection $ingredients): int
    {
        $ingredients->each(fn (Ingredient $ingredient) => Gate::forUser($actor)->authorize('markCatalogReviewed', $ingredient));

        return $this->reviews->markReviewed($actor, $ingredients);
    }
}
