<?php

namespace Database\Factories;

use App\Models\Substance;
use App\Models\ToxicologyProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ToxicologyProfile>
 */
class ToxicologyProfileFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'substance_id' => Substance::factory(),
            'version' => 1,
            'is_current' => true,
            'superseded_at' => null,
            'noael_mg_kg_bw_day' => '100',
            'noael_route' => 'oral',
            'dermal_absorption_percent' => null,
            'references' => [['number' => 1, 'citation' => 'Factory reference', 'url' => null]],
            'value_references' => ['noael_mg_kg_bw_day' => [1]],
        ];
    }
}
