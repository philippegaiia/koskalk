<?php

namespace Database\Factories;

use App\Enums\NominalContentUnit;
use App\Models\ProductSize;
use App\Models\Recipe;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductSize>
 */
class ProductSizeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'recipe_id' => Recipe::factory(),
            'name' => null,
            'declared_content_value' => '100.0000',
            'declared_content_unit' => NominalContentUnit::Gram,
            'position' => 1,
        ];
    }
}
