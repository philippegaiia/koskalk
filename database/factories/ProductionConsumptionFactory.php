<?php

namespace Database\Factories;

use App\Models\ProductionConsumption;
use App\Models\ProductionRun;
use App\ProductionConsumptionKind;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductionConsumption>
 */
class ProductionConsumptionFactory extends Factory
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
            'production_requirement_id' => null,
            'stock_lot_id' => null,
            'kind' => ProductionConsumptionKind::Ingredient,
            'subject_name_snapshot' => fake()->words(2, true),
            'quantity' => '100.000000000',
            'unit_snapshot' => 'g',
            'price_per_unit' => null,
            'line_cost' => null,
            'recorded_by_user_id' => null,
            'note' => null,
        ];
    }

    public function forPackaging(): static
    {
        return $this->state(fn (): array => [
            'kind' => ProductionConsumptionKind::Packaging,
            'quantity' => 1,
            'unit_snapshot' => 'unit',
        ]);
    }
}
