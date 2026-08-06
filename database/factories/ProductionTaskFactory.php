<?php

namespace Database\Factories;

use App\Models\ProductionRun;
use App\Models\ProductionTask;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductionTask>
 */
class ProductionTaskFactory extends Factory
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
            'production_run_id' => ProductionRun::factory(),
            'production_task_set_id' => null,
            'production_task_set_item_id' => null,
            'employee_id' => null,
            'department_id' => null,
            'name_snapshot' => fake()->words(2, true),
            'days_after_production' => 0,
            'duration_minutes' => null,
            'scheduled_for' => today(),
            'scheduling_mode' => 'automatic',
            'completed_at' => null,
        ];
    }
}
