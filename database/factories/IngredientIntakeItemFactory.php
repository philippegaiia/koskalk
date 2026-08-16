<?php

namespace Database\Factories;

use App\Enums\IngredientIntakeItemStatus;
use App\Models\IngredientIntakeBatch;
use App\Models\IngredientIntakeItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IngredientIntakeItem>
 */
class IngredientIntakeItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $currentName = fake()->words(2, true);

        return [
            'ingredient_intake_batch_id' => IngredientIntakeBatch::factory(),
            'row_number' => fake()->unique()->numberBetween(1, 1000),
            'original_current_name' => $currentName,
            'normalized_current_name' => strtolower($currentName),
            'original_inci_name' => null,
            'normalized_inci_name' => null,
            'status' => IngredientIntakeItemStatus::Draft,
            'duplicate_candidates' => [],
            'duplicate_resolution' => null,
            'existing_ingredient_id' => null,
            'promoted_ingredient_id' => null,
            'failure_code' => null,
            'failure_message' => null,
        ];
    }
}
