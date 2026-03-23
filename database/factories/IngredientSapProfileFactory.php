<?php

namespace Database\Factories;

use App\Models\IngredientSapProfile;
use App\Models\IngredientVersion;
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
            'ingredient_version_id' => IngredientVersion::factory(),
            'koh_sap_value' => fake()->randomFloat(6, 0.14, 0.42),
            'lauric' => null,
            'myristic' => null,
            'palmitic' => null,
            'stearic' => null,
            'ricinoleic' => null,
            'oleic' => null,
            'linoleic' => null,
            'linolenic' => null,
            'source_notes' => fake()->sentence(),
        ];
    }
}
