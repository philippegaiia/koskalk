<?php

namespace Database\Factories;

use App\Models\ProductionBatch;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductionBatch>
 */
class ProductionBatchFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'recipe_id' => Recipe::factory(),
            'recipe_version_id' => RecipeVersion::factory(),
            'recipe_name' => fake()->words(3, true),
            'recipe_version_number' => 1,
            'product_family_slug' => 'soap',
            'production_batch_number' => fake()->bothify('B-####-###'),
            'manufacture_date' => today(),
            'batch_basis_label' => 'Oil quantity',
            'batch_basis_value' => 1000,
            'batch_basis_unit' => 'g',
            'units_produced' => 12,
            'currency' => 'EUR',
            'ingredient_total' => 8.5,
            'packaging_total' => 3,
            'total_cost' => 11.5,
            'cost_per_unit' => 0.9583,
            'production_notes' => null,
        ];
    }
}
