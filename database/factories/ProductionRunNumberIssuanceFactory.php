<?php

namespace Database\Factories;

use App\Models\ProductionRunNumberIssuance;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductionRunNumberIssuance>
 */
class ProductionRunNumberIssuanceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'production_run_id' => null,
            'batch_number' => 'B-'.fake()->unique()->numerify('#####'),
            'serial' => fake()->unique()->numberBetween(1, 99999),
            'issued_by_user_id' => null,
            'issued_at' => now(),
        ];
    }
}
