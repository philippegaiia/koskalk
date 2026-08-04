<?php

namespace Database\Factories;

use App\Models\ProductionTaskSet;
use App\Models\ProductionTaskSetItem;
use App\Models\ProductionTaskType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductionTaskSetItem>
 */
class ProductionTaskSetItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'production_task_set_id' => ProductionTaskSet::factory(),
            'production_task_type_id' => ProductionTaskType::factory(),
            'position' => 1,
            'days_after_production' => 0,
            'duration_minutes' => null,
        ];
    }
}
