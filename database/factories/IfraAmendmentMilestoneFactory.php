<?php

namespace Database\Factories;

use App\Enums\IfraCreationTrack;
use App\Enums\IfraStandardKind;
use App\Models\IfraAmendment;
use App\Models\IfraAmendmentMilestone;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IfraAmendmentMilestone>
 */
class IfraAmendmentMilestoneFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ifra_amendment_id' => IfraAmendment::factory(),
            'standard_kind' => fake()->randomElement(IfraStandardKind::cases()),
            'creation_track' => fake()->randomElement(IfraCreationTrack::cases()),
            'effective_on' => fake()->date(),
        ];
    }
}
