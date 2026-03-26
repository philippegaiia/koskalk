<?php

namespace Database\Factories;

use App\Models\FattyAcid;
use App\Models\IngredientVersion;
use App\Models\IngredientVersionFattyAcid;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IngredientVersionFattyAcid>
 */
class IngredientVersionFattyAcidFactory extends Factory
{
    protected $model = IngredientVersionFattyAcid::class;

    public function definition(): array
    {
        return [
            'ingredient_version_id' => IngredientVersion::factory(),
            'fatty_acid_id' => FattyAcid::factory(),
            'percentage' => fake()->randomFloat(5, 0.1, 95),
            'source_notes' => fake()->sentence(),
            'source_data' => null,
        ];
    }
}
