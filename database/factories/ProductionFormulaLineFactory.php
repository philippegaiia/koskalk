<?php

namespace Database\Factories;

use App\Models\ProductionFormulaLine;
use App\Models\ProductionRun;
use App\ProductionFormulaComponent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductionFormulaLine>
 */
class ProductionFormulaLineFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'production_run_id' => ProductionRun::factory(),
            'ingredient_id' => null,
            'recipe_item_id' => null,
            'component' => ProductionFormulaComponent::Ingredient,
            'subject_name_snapshot' => fake()->words(2, true),
            'phase_key_snapshot' => 'main',
            'phase_name_snapshot' => 'Main',
            'basis_percentage_snapshot' => '10.000000000',
            'planned_mass_grams' => '100.000000000',
            'note_snapshot' => null,
            'sort_order' => 1,
        ];
    }
}
