<?php

namespace Database\Factories;

use App\Enums\IngredientCategory;
use App\Enums\Visibility;
use App\Models\Ingredient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ingredient>
 */
class IngredientFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'catalog_key' => fake()->unique()->bothify('ING###'),
            'category' => IngredientCategory::Additive,
            'display_name' => fake()->words(2, true),
            'inci_name' => strtoupper(fake()->words(2, true)),
            'soap_inci_naoh_name' => null,
            'soap_inci_koh_name' => null,
            'cas_number' => null,
            'ec_number' => null,
            'unit' => 'g',
            'owner_type' => null,
            'owner_id' => null,
            'workspace_id' => null,
            'visibility' => Visibility::Public,
            'is_potentially_saponifiable' => false,
            'requires_admin_review' => true,
            'is_active' => true,
            'is_manufactured' => false,
            'source_data' => null,
            'info_markdown' => null,
            'notes' => null,
            'featured_image_path' => null,
            'icon_image_path' => null,
        ];
    }
}
