<?php

namespace Database\Factories;

use App\Models\Allergen;
use App\Models\Ingredient;
use App\Models\IngredientAllergenEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IngredientAllergenEntry>
 */
class IngredientAllergenEntryFactory extends Factory
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
            'allergen_id' => Allergen::factory(),
            'concentration_percent' => fake()->randomFloat(5, 0.0001, 5),
            'source_notes' => fake()->sentence(),
            'source_data' => null,
        ];
    }
}
