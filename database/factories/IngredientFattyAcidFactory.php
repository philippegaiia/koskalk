<?php

namespace Database\Factories;

use App\Models\FattyAcid;
use App\Models\Ingredient;
use App\Models\IngredientFattyAcid;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IngredientFattyAcid>
 */
class IngredientFattyAcidFactory extends Factory
{
    protected $model = IngredientFattyAcid::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ingredient_id' => Ingredient::factory(),
            'fatty_acid_id' => FattyAcid::factory(),
            'percentage' => fake()->randomFloat(5, 0.1, 95),
            'source_notes' => fake()->sentence(),
            'source_data' => null,
        ];
    }
}
