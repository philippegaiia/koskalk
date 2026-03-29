<?php

namespace Database\Factories;

use App\Models\Ingredient;
use App\Models\IngredientComponent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IngredientComponent>
 */
class IngredientComponentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ingredient_id' => Ingredient::factory(),
            'component_ingredient_id' => Ingredient::factory(),
            'percentage_in_parent' => fake()->randomFloat(5, 0.1, 100),
            'sort_order' => 1,
            'source_notes' => fake()->optional()->sentence(),
            'source_data' => null,
        ];
    }
}
