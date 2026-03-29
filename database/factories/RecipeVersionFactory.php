<?php

namespace Database\Factories;

use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\OwnerType;
use App\Visibility;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecipeVersion>
 */
class RecipeVersionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'recipe_id' => Recipe::factory(),
            'owner_type' => OwnerType::User,
            'owner_id' => 1,
            'workspace_id' => null,
            'visibility' => Visibility::Private,
            'version_number' => 1,
            'is_draft' => true,
            'name' => fake()->words(3, true),
            'batch_size' => 1000,
            'batch_unit' => 'g',
            'manufacturing_mode' => 'saponify_in_formula',
            'exposure_mode' => 'rinse_off',
            'regulatory_regime' => 'eu',
            'notes' => null,
            'water_settings' => null,
            'calculation_context' => null,
            'saved_at' => null,
            'catalog_reviewed_at' => now(),
            'archived_at' => null,
        ];
    }
}
