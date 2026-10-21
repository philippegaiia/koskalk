<?php

namespace Database\Factories;

use App\Models\IfraAmendment;
use App\Models\IfraProductCategory;
use App\Models\Qra2Parameter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Qra2Parameter>
 */
class Qra2ParameterFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ifra_product_category_id' => IfraProductCategory::factory(),
            'ifra_amendment_id' => IfraAmendment::factory(),
            'preset_label' => 'Bar soap — IFRA category 9 — amendment 51',
            'version' => 1,
            'is_current' => true,
            'superseded_at' => null,
            'sensitization_assessment_factor' => '300',
            'consumer_exposure_level_mg_cm2_day' => '0.2',
            'qra2_factor' => '0.5',
            'applies_qra2_factor' => true,
            'factor_deviation_reason' => null,
            'source_reference' => 'Factory QRA2 parameters',
        ];
    }
}
