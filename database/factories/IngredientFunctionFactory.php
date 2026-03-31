<?php

namespace Database\Factories;

use App\Models\IngredientFunction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IngredientFunction>
 */
class IngredientFunctionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => fake()->unique()->slug(2),
            'name' => ucfirst(fake()->unique()->words(2, true)),
            'description' => fake()->sentence(),
            'sort_order' => fake()->numberBetween(1, 100),
            'is_active' => true,
        ];
    }
}
