<?php

namespace Database\Factories;

use App\Models\Ingredient;
use App\Models\IngredientVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IngredientVersion>
 */
class IngredientVersionFactory extends Factory
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
            'version' => 1,
            'is_current' => true,
            'display_name' => fake()->words(2, true),
            'display_name_en' => fake()->words(2, true),
            'display_name_fr' => fake()->words(2, true),
            'inci_name' => fake()->words(3, true),
            'soap_inci_naoh_name' => null,
            'soap_inci_koh_name' => null,
            'cas_number' => fake()->optional()->numerify('####-##-##'),
            'ec_number' => fake()->optional()->numerify('###-###-#'),
            'unit' => 'kg',
            'price_eur' => fake()->randomFloat(2, 1, 20),
            'is_active' => true,
            'is_manufactured' => false,
            'source_file' => 'factory',
            'source_key' => fake()->unique()->bothify('ING###'),
            'source_data' => null,
        ];
    }
}
