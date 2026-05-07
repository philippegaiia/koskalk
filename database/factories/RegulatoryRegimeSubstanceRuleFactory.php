<?php

namespace Database\Factories;

use App\Models\RegulatoryRegime;
use App\Models\RegulatoryRegimeSubstanceRule;
use App\Models\Substance;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RegulatoryRegimeSubstanceRule>
 */
class RegulatoryRegimeSubstanceRuleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'regulatory_regime_id' => RegulatoryRegime::factory(),
            'substance_id' => Substance::factory(),
            'rule_type' => 'watch',
            'rinse_off_max_percent' => null,
            'leave_on_max_percent' => null,
            'threshold_operator' => 'less_than_or_equal',
            'exposure_scope' => 'both',
            'label_warning_text' => null,
            'is_active' => true,
            'effective_from' => null,
            'effective_until' => null,
            'source_reference' => null,
            'source_data' => null,
        ];
    }
}
