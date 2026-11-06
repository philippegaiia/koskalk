<?php

namespace Database\Factories;

use App\Models\Brand;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Brand> */
class BrandFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return ['workspace_id' => Workspace::factory(), 'name' => $name, 'slug' => Str::slug($name)];
    }
}
