<?php

namespace Database\Factories;

use App\Models\Ingredient;
use App\Models\ProductionBatch;
use App\Models\ProductionBatchIngredient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductionBatchIngredient>
 */
class ProductionBatchIngredientFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'production_batch_id' => ProductionBatch::factory(),
            'ingredient_id' => Ingredient::factory(),
            'raw_material_lot_id' => null,
            'phase_key' => 'saponified_oils',
            'phase_name' => 'Saponified oils',
            'position' => 1,
            'ingredient_name' => 'Olive Oil',
            'percentage' => 100,
            'quantity' => 1000,
            'unit' => 'g',
            'price_per_kg' => 8.5,
            'line_cost' => 8.5,
            'ingredient_lot_number' => null,
        ];
    }
}
