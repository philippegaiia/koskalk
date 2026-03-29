<?php

namespace Database\Factories;

use App\Models\ProductFamily;
use App\Models\Recipe;
use App\OwnerType;
use App\Visibility;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Recipe>
 */
class RecipeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = Str::title(fake()->unique()->words(3, true));

        return [
            'product_family_id' => ProductFamily::factory(),
            'owner_type' => OwnerType::User,
            'owner_id' => 1,
            'workspace_id' => null,
            'visibility' => Visibility::Private,
            'name' => $name,
            'description' => null,
            'featured_image_path' => null,
            'slug' => Str::slug($name),
            'archived_at' => null,
        ];
    }
}
