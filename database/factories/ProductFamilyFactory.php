<?php

namespace Database\Factories;

use App\Models\ProductFamily;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProductFamily>
 */
class ProductFamilyFactory extends Factory
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
            'name' => Str::title($name),
            'slug' => Str::slug($name),
            'calculation_basis' => 'initial_oils',
            'is_active' => true,
            'description' => fake()->sentence(),
        ];
    }
}
