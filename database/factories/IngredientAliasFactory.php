<?php

namespace Database\Factories;

use App\Enums\IngredientAliasKind;
use App\Models\Ingredient;
use App\Models\IngredientAlias;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IngredientAlias>
 */
class IngredientAliasFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->words(2, true);

        return [
            'ingredient_id' => Ingredient::factory(),
            'locale' => 'und',
            'name' => $name,
            'normalized_name' => mb_strtolower($name),
            'kind' => IngredientAliasKind::Common,
        ];
    }
}
