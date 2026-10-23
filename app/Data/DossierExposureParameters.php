<?php

namespace App\Data;

use App\Support\DecimalMath;

/**
 * Exposure inputs of one DIP calculation, as decimal strings: amount per use (g), uses per day, retention factor R and
 * body weight (kg). Taken from the product type's exposure preset and the assessment policy, or a justified override.
 */
final readonly class DossierExposureParameters
{
    public function __construct(
        public string $amountPerUseGrams,
        public string $usesPerDay,
        public string $retentionFactor,
        public string $bodyWeightKg,
    ) {}

    /**
     * A [g/day] = amount per use × frequency.
     */
    public function dailyAmountGrams(): string
    {
        return DecimalMath::mul($this->amountPerUseGrams, $this->usesPerDay);
    }
}
