<?php

namespace Database\Factories;

use App\Models\IfraCertificate;
use App\Models\Ingredient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IfraCertificate>
 */
class IfraCertificateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ingredient_id' => Ingredient::factory(),
            'certificate_name' => fake()->words(3, true).' IFRA Certificate',
            'document_name' => fake()->words(3, true).'.pdf',
            'document_path' => null,
            'issuer' => fake()->company(),
            'reference_code' => strtoupper(fake()->bothify('IFRA-###??')),
            'ifra_amendment' => (string) fake()->numberBetween(48, 51),
            'published_at' => fake()->date(),
            'valid_from' => fake()->date(),
            'is_current' => true,
            'source_notes' => fake()->sentence(),
            'source_data' => null,
        ];
    }
}
