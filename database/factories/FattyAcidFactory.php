<?php

namespace Database\Factories;

use App\Models\FattyAcid;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FattyAcid>
 */
class FattyAcidFactory extends Factory
{
    protected $model = FattyAcid::class;

    public function definition(): array
    {
        return [
            'key' => fake()->unique()->slug(),
            'name' => fake()->unique()->word(),
            'short_name' => null,
            'chain_length' => fake()->numberBetween(8, 24),
            'double_bonds' => fake()->numberBetween(0, 3),
            'saturation_class' => fake()->randomElement(['saturated', 'monounsaturated', 'polyunsaturated', 'hydroxy_unsaturated']),
            'iodine_factor' => fake()->randomFloat(3, 0, 3),
            'default_group_key' => null,
            'display_order' => fake()->numberBetween(1, 99),
            'is_core' => false,
            'is_active' => true,
            'default_hidden_below_percent' => 0.5,
            'source_data' => null,
        ];
    }
}
