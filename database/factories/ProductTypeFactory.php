<?php

namespace Database\Factories;

use App\Models\ProductFamily;
use App\Models\ProductType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProductType>
 */
class ProductTypeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'product_family_id' => ProductFamily::factory(),
            'default_ifra_product_category_id' => null,
            'name' => Str::title($name),
            'slug' => Str::slug($name),
            'fallback_image_path' => null,
            'sort_order' => fake()->numberBetween(1, 100),
            'is_active' => true,
            'description' => fake()->optional()->sentence(),
        ];
    }
}
