<?php

namespace Database\Factories;

use App\Models\IfraCertificate;
use App\Models\IfraCertificateLimit;
use App\Models\IfraProductCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IfraCertificateLimit>
 */
class IfraCertificateLimitFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ifra_certificate_id' => IfraCertificate::factory(),
            'ifra_product_category_id' => IfraProductCategory::factory(),
            'max_percentage' => fake()->randomFloat(5, 0.001, 100),
            'restriction_note' => fake()->sentence(),
            'source_data' => null,
        ];
    }
}
