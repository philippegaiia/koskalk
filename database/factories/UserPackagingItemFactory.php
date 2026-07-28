<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserPackagingItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserPackagingItem>
 */
class UserPackagingItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->words(2, true),
            'unit_cost' => fake()->randomFloat(4, 0.01, 25),
            'currency' => 'EUR',
            'notes' => null,
        ];
    }
}
