<?php

namespace Database\Factories;

use App\Models\Ingredient;
use App\Models\Workspace;
use App\Models\WorkspaceIngredientCode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkspaceIngredientCode>
 */
class WorkspaceIngredientCodeFactory extends Factory
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
            'ingredient_id' => Ingredient::factory(),
            'material_code' => fake()->unique()->regexify('[A-Z]{2,4}-[A-Z0-9]{2,8}'),
        ];
    }
}
