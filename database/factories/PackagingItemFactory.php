<?php

namespace Database\Factories;

use App\Enums\PackagingCategory;
use App\Models\PackagingItem;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PackagingItem>
 */
class PackagingItemFactory extends Factory
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
            'created_by_user_id' => null,
            'name' => fake()->words(2, true),
            'material_code' => null,
            'category' => PackagingCategory::Other,
            'notes' => null,
            'is_active' => true,
        ];
    }
}
