<?php

namespace Database\Factories;

use App\Models\ProductionLocation;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProductionLocation> */
class ProductionLocationFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->words(3, true);

        return ['workspace_id' => Workspace::factory(), 'name' => $name,
            'normalized_name' => mb_strtolower($name), 'is_active' => true, 'daily_production_limit' => 1];
    }
}
