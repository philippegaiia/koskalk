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
        $amendment = (string) fake()->numberBetween(48, 51);

        return [
            'ingredient_id' => Ingredient::factory(),
            'certificate_name' => fake()->words(3, true).' IFRA Certificate',
            'document_name' => fake()->words(3, true).'.pdf',
            'document_path' => null,
            'issuer' => fake()->company(),
            'reference_code' => strtoupper(fake()->bothify('IFRA-###??')),
            'ifra_amendment' => $amendment,
            'ifra_amendment_id' => null,
            'source_amendment_label' => $amendment,
            'published_at' => fake()->date(),
            'valid_from' => fake()->date(),
            'is_current' => true,
            'source_notes' => fake()->sentence(),
            'source_data' => null,
        ];
    }
}
