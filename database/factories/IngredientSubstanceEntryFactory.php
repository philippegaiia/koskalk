<?php

namespace Database\Factories;

use App\Models\Ingredient;
use App\Models\IngredientSubstanceEntry;
use App\Models\Substance;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IngredientSubstanceEntry>
 */
class IngredientSubstanceEntryFactory extends Factory
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
            'substance_id' => Substance::factory(),
            'concentration_percent' => fake()->randomFloat(5, 0.0001, 5),
            'concentration_source' => 'supplier',
            'source_notes' => fake()->sentence(),
            'source_data' => null,
        ];
    }
}
