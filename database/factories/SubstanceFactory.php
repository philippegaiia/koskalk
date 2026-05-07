<?php

namespace Database\Factories;

use App\Models\Substance;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Substance>
 */
class SubstanceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => Str::title(fake()->unique()->words(2, true)),
            'entity_type' => 'constituent',
            'inci_name' => null,
            'cas_number' => null,
            'ec_number' => null,
            'synonyms' => null,
            'allergen_id' => null,
            'source_name' => 'Factory',
            'source_url' => null,
            'notes' => null,
            'source_data' => null,
        ];
    }
}
