<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Department>
 */
class DepartmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'workspace_id' => Workspace::factory(),
            'name' => $name,
            'normalized_name' => mb_strtolower($name),
            'is_active' => true,
        ];
    }
}
