<?php

namespace Database\Factories;

use App\Models\ProductionTaskType;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductionTaskType>
 */
class ProductionTaskTypeFactory extends Factory
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
            'name' => fake()->words(2, true),
            'default_duration_minutes' => null,
            'colour' => null,
            'is_active' => true,
        ];
    }
}
