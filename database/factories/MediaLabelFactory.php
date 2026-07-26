<?php

namespace Database\Factories;

use App\Models\MediaLabel;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MediaLabel>
 */
class MediaLabelFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'created_by_user_id' => User::factory(),
            'name' => fake()->unique()->words(2, true),
            'normalized_name' => fn (array $attributes): string => mb_strtolower($attributes['name']),
        ];
    }
}
