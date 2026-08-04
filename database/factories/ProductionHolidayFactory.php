<?php

namespace Database\Factories;

use App\Models\ProductionHoliday;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductionHoliday>
 */
class ProductionHolidayFactory extends Factory
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
            'date' => today()->addMonth(),
            'is_recurring' => false,
        ];
    }
}
