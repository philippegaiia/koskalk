<?php

namespace Database\Factories;

use App\Models\Plan;
use App\Models\User;
use App\Models\UserEntitlement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserEntitlement>
 */
class UserEntitlementFactory extends Factory
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
            'plan_id' => Plan::factory(),
            'status' => 'active',
            'source' => 'manual',
            'starts_at' => now(),
            'ends_at' => null,
        ];
    }
}
