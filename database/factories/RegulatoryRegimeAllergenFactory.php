<?php

namespace Database\Factories;

use App\Models\Allergen;
use App\Models\RegulatoryRegime;
use App\Models\RegulatoryRegimeAllergen;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RegulatoryRegimeAllergen>
 */
class RegulatoryRegimeAllergenFactory extends Factory
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
            'allergen_id' => Allergen::factory(),
            'declaration_label' => null,
            'rinse_off_threshold_percent' => 0.01000,
            'leave_on_threshold_percent' => 0.00100,
            'threshold_operator' => 'greater_than_or_equal',
            'group_key' => null,
            'group_label' => null,
            'is_active' => true,
            'effective_from' => null,
            'effective_until' => null,
            'source_reference' => null,
            'source_data' => null,
        ];
    }
}
