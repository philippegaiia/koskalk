<?php

namespace Database\Factories;

use App\Models\RegulatoryRegime;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RegulatoryRegime>
 */
class RegulatoryRegimeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->slug(2),
            'market_code' => fake()->lexify('??'),
            'name' => fake()->words(2, true),
            'version_label' => fake()->words(3, true),
            'status' => 'active',
            'is_default' => false,
            'effective_from' => null,
            'effective_until' => null,
            'source_name' => 'Test source',
            'source_url' => null,
            'reviewed_at' => now(),
            'notes' => null,
            'source_data' => null,
        ];
    }
}
