<?php

namespace Database\Factories;

use App\Enums\LegalEntryReviewStatus;
use App\Models\RegulatoryLegalEntry;
use App\Models\RegulatoryRegime;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RegulatoryLegalEntry>
 */
class RegulatoryLegalEntryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'regulatory_regime_id' => RegulatoryRegime::factory(),
            'annex' => 'III',
            'entry_reference' => 'III/'.fake()->unique()->numberBetween(1, 400),
            'clause' => null,
            'substance_description' => fake()->words(2, true),
            'product_scope' => 'all_products',
            'product_type_body_parts' => null,
            'max_concentration_percent' => null,
            'other_limitations' => null,
            'label_warnings_fr' => null,
            'effective_from' => null,
            'effective_until' => null,
            'placing_on_market_until' => null,
            'making_available_until' => null,
            'source_reference' => 'Regulation (EC) No 1223/2009, consolidated text (EUR-Lex)',
            'review_status' => LegalEntryReviewStatus::Draft,
            'reviewed_by_user_id' => null,
            'reviewed_at' => null,
        ];
    }

    public function reviewed(): static
    {
        return $this->state(fn (): array => [
            'review_status' => LegalEntryReviewStatus::Reviewed,
            'reviewed_by_user_id' => User::factory()->admin(),
            'reviewed_at' => now(),
        ]);
    }
}
