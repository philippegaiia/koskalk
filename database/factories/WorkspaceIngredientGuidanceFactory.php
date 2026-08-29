<?php

namespace Database\Factories;

use App\Models\Ingredient;
use App\Models\Workspace;
use App\Models\WorkspaceIngredientGuidance;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkspaceIngredientGuidance>
 */
class WorkspaceIngredientGuidanceFactory extends Factory
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
            'ingredient_id' => Ingredient::factory()->state([
                'owner_type' => null,
                'owner_id' => null,
                'workspace_id' => null,
                'is_active' => true,
            ]),
            'guidance_markdown' => "## Overview\n\n".fake()->paragraph(),
            'created_by_user_id' => null,
            'updated_by_user_id' => null,
        ];
    }
}
