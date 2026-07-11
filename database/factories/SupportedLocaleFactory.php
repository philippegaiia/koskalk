<?php

namespace Database\Factories;

use App\Models\SupportedLocale;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupportedLocale>
 */
class SupportedLocaleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->languageCode(),
            'name' => fake()->languageCode(),
            'native_name' => fake()->languageCode(),
            'number_locale' => 'en_US',
            'text_direction' => 'ltr',
            'is_active' => true,
            'is_default' => false,
            'sort_order' => 10,
        ];
    }
}
