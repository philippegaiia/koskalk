<?php

namespace Database\Factories;

use App\Models\IfraAmendment;
use App\Models\IfraProductCategory;
use App\Models\ProductType;
use App\Models\ProductTypeIfraCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductTypeIfraCategory>
 */
class ProductTypeIfraCategoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_type_id' => ProductType::factory(),
            'ifra_amendment_id' => IfraAmendment::factory(),
            'ifra_product_category_id' => IfraProductCategory::factory(),
            'is_default' => false,
            'guidance' => fake()->optional()->sentence(),
            'source_url' => fake()->optional()->url(),
            'sort_order' => fake()->numberBetween(1, 100),
            'is_active' => true,
        ];
    }
}
