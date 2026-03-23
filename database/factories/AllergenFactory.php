<?php

namespace Database\Factories;

use App\Models\Allergen;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Allergen>
 */
class AllergenFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source_name' => 'EU allergen list',
            'source_file' => 'factory',
            'inci_name' => strtoupper(fake()->unique()->lexify('??????????')),
            'cas_number' => fake()->optional()->numerify('#####-##-#'),
            'ec_number' => fake()->optional()->numerify('###-###-#'),
            'common_name_en' => fake()->optional()->words(2, true),
            'common_name_fr' => fake()->optional()->words(2, true),
            'source_data' => null,
        ];
    }
}
