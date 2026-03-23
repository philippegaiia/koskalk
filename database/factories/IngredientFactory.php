<?php

namespace Database\Factories;

use App\IngredientCategory;
use App\Models\Ingredient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ingredient>
 */
class IngredientFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source_file' => 'factory',
            'source_key' => fake()->unique()->bothify('ING###'),
            'source_code_prefix' => 'ING',
            'category' => IngredientCategory::Additive,
            'is_potentially_saponifiable' => false,
            'requires_admin_review' => true,
            'is_active' => true,
            'source_data' => null,
        ];
    }
}
