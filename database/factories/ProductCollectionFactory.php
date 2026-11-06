<?php

namespace Database\Factories;

use App\Models\Brand;
use App\Models\ProductCollection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ProductCollection> */
class ProductCollectionFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'brand_id' => Brand::factory(),
            'workspace_id' => fn (array $attributes): int => Brand::query()->findOrFail($attributes['brand_id'])->workspace_id,
            'name' => $name,
            'slug' => Str::slug($name),
        ];
    }
}
