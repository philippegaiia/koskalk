<?php

namespace Database\Factories;

use App\Models\IfraProductCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IfraProductCategory>
 */
class IfraProductCategoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => (string) fake()->unique()->numberBetween(1, 12),
            'name' => 'IFRA Category '.fake()->numberBetween(1, 12),
            'short_name' => fake()->optional()->bothify('CAT-##'),
            'description' => fake()->sentence(),
            'is_active' => true,
        ];
    }
}
