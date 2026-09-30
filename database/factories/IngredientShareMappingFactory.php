<?php

namespace Database\Factories;

use App\Models\Ingredient;
use App\Models\IngredientShareMapping;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<IngredientShareMapping> */
class IngredientShareMappingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'lineage_key' => (string) Str::uuid(),
            'fingerprint_version' => 1,
            'incoming_fingerprint' => hash('sha256', fake()->uuid()),
            'ingredient_id' => Ingredient::factory(),
            'local_fingerprint' => hash('sha256', fake()->uuid()),
            'resolution' => 'exact',
        ];
    }
}
