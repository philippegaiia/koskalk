<?php

namespace Database\Factories;

use App\MassUnit;
use App\Models\ProductionBatchPreset;
use App\Models\Recipe;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductionBatchPreset>
 */
class ProductionBatchPresetFactory extends Factory
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
            'recipe_id' => Recipe::factory(),
            'name' => fake()->words(2, true),
            'basis_quantity_grams' => '12000.000000000',
            'basis_input_value' => '12.000000000',
            'basis_input_unit' => MassUnit::Kilogram,
            'expected_units' => 100,
            'is_default' => false,
            'is_active' => true,
        ];
    }
}
