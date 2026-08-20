<?php

namespace Database\Factories;

use App\Enums\IfraAmendmentStatus;
use App\Models\IfraAmendment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IfraAmendment>
 */
class IfraAmendmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => (string) fake()->unique()->numberBetween(40, 999),
            'status' => IfraAmendmentStatus::Notified,
            'notification_date' => fake()->date(),
            'source_url' => fake()->optional()->url(),
            'notes' => fake()->optional()->sentence(),
        ];
    }
}
