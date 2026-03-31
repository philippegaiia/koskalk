<?php

namespace Database\Factories;

use App\Models\Ingredient;
use App\Models\RecipeItem;
use App\Models\RecipePhase;
use App\Models\RecipeVersion;
use App\OwnerType;
use App\Visibility;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecipeItem>
 */
class RecipeItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'recipe_version_id' => RecipeVersion::factory(),
            'recipe_phase_id' => RecipePhase::factory(),
            'ingredient_id' => Ingredient::factory(),
            'owner_type' => OwnerType::User,
            'owner_id' => 1,
            'workspace_id' => null,
            'visibility' => Visibility::Private,
            'position' => 1,
            'percentage' => fake()->randomFloat(4, 1, 100),
            'weight' => fake()->randomFloat(4, 1, 1000),
            'note' => null,
        ];
    }
}
