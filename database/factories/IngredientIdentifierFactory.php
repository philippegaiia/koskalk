<?php

namespace Database\Factories;

use App\Enums\IngredientIdentifierScheme;
use App\Models\Ingredient;
use App\Models\IngredientIdentifier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IngredientIdentifier>
 */
class IngredientIdentifierFactory extends Factory
{
    public function definition(): array
    {
        $value = fake()->numerify('#####-##-#');

        return [
            'ingredient_id' => Ingredient::factory(),
            'scheme' => IngredientIdentifierScheme::Cas,
            'value' => $value,
            'normalized_value' => $value,
            'is_primary' => true,
        ];
    }
}
