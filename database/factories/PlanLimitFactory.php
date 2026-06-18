<?php

namespace Database\Factories;

use App\Models\Plan;
use App\Models\PlanLimit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlanLimit>
 */
class PlanLimitFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'plan_id' => Plan::factory(),
            'key' => fake()->unique()->randomElement(['saved_recipes', 'private_ingredients']),
            'value' => fake()->numberBetween(10, 100),
        ];
    }
}
