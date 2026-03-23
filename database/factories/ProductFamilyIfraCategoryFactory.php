<?php

namespace Database\Factories;

use App\Models\IfraProductCategory;
use App\Models\ProductFamily;
use App\Models\ProductFamilyIfraCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductFamilyIfraCategory>
 */
class ProductFamilyIfraCategoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_family_id' => ProductFamily::factory(),
            'ifra_product_category_id' => IfraProductCategory::factory(),
            'is_default' => fake()->boolean(),
            'sort_order' => fake()->numberBetween(1, 5),
        ];
    }
}
