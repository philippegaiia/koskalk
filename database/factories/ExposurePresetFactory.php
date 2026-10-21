<?php

namespace Database\Factories;

use App\Models\ExposurePreset;
use App\Models\ProductType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExposurePreset>
 */
class ExposurePresetFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_type_id' => ProductType::factory(),
            'version' => 1,
            'is_current' => true,
            'superseded_at' => null,
            'amount_per_use_g' => '1',
            'uses_per_day' => '10',
            'retention_factor' => '0.01',
            'is_rinse_off' => true,
            'application_sites' => ['body', 'face'],
            'direct_routes' => ['dermal'],
            'secondary_routes' => ['ocular'],
            'target_population' => 'adults',
            'source_reference' => 'Factory preset',
        ];
    }
}
