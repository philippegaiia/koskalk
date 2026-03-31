<?php

namespace Database\Factories;

use App\Models\Ingredient;
use App\Models\IngredientSapProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IngredientSapProfile>
 */
class IngredientSapProfileFactory extends Factory
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
            'koh_sap_value' => fake()->randomFloat(6, 0.14, 0.42),
            'iodine_value' => null,
            'ins_value' => null,
            'source_notes' => fake()->sentence(),
        ];
    }
}
