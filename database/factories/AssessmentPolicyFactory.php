<?php

namespace Database\Factories;

use App\Models\AssessmentPolicy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AssessmentPolicy>
 */
class AssessmentPolicyFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'version' => 1,
            'is_current' => true,
            'superseded_at' => null,
            'default_dermal_absorption_percent' => '100',
            'default_oral_bioavailability_percent' => '50',
            'mos_threshold' => '100',
            'body_weight_kg' => '60',
            'source_reference' => 'Factory policy',
        ];
    }
}
