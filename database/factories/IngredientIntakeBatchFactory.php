<?php

namespace Database\Factories;

use App\Enums\IngredientIntakeBatchStatus;
use App\Enums\IngredientIntakeInputMethod;
use App\Models\IngredientIntakeBatch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IngredientIntakeBatch>
 */
class IngredientIntakeBatchFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'created_by_user_id' => User::factory(),
            'status' => IngredientIntakeBatchStatus::Draft,
            'name' => fake()->sentence(3),
            'notes' => null,
            'input_method' => IngredientIntakeInputMethod::Paste,
            'family_hint' => null,
            'allow_gap_research' => false,
            'original_filename' => null,
            'storage_disk' => null,
            'storage_path' => null,
        ];
    }
}
