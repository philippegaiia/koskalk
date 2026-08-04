<?php

namespace Database\Factories;

use App\Models\ProductionTaskSet;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductionTaskSet>
 */
class ProductionTaskSetFactory extends Factory
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
            'is_active' => true,
        ];
    }
}
