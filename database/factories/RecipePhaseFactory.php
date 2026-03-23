<?php

namespace Database\Factories;

use App\Models\RecipePhase;
use App\Models\RecipeVersion;
use App\OwnerType;
use App\Visibility;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RecipePhase>
 */
class RecipePhaseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = Str::title(fake()->unique()->words(2, true));

        return [
            'recipe_version_id' => RecipeVersion::factory(),
            'owner_type' => OwnerType::User,
            'owner_id' => 1,
            'workspace_id' => null,
            'visibility' => Visibility::Private,
            'name' => $name,
            'slug' => Str::slug($name),
            'phase_type' => null,
            'sort_order' => 1,
            'is_system' => false,
        ];
    }
}
