<?php

namespace Database\Factories;

use App\Enums\MaterialPriceSource;
use App\Models\CurrentMaterialPrice;
use App\Models\Ingredient;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CurrentMaterialPrice>
 */
class CurrentMaterialPriceFactory extends Factory
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
            'packaging_item_id' => null,
            'price_per_canonical_unit' => '0.001000000000',
            'currency' => 'EUR',
            'recorded_at' => now(),
            'source_type' => MaterialPriceSource::ManualCosting,
            'source_id' => null,
            'created_by_user_id' => null,
        ];
    }
}
