<?php

namespace Database\Factories;

use App\Models\ProductionOutputSetting;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductionOutputSetting>
 */
class ProductionOutputSettingFactory extends Factory
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
            'soap_ready_delay_days' => 21,
            'cosmetic_ready_delay_days' => 3,
        ];
    }
}
