<?php

namespace App\Services\Dossiers\Calculation;

use App\Models\Qra2Parameter;
use App\Support\DecimalMath;

/**
 * IFRA QRA2 for fragrance allergens (IFRA 51st Amendment guidance, Table 6 p. 28 and worked example Table 7 p. 29).
 * QRA2 addresses the induction of sensitisation only; it does not protect people already sensitised.
 */
class Qra2Calculator
{
    /**
     * AEL [µg/cm²/day] = NESIL ÷ SAF; Cmax % = AEL ÷ (CEL [mg/cm²/day] × 1000) × 100 × aggregate adjustment factor.
     *
     * @return array{ael_ug_cm2: string, cmax_without_factor_percent: string, factor: string|null, cmax_percent: string}
     */
    public function maximumConcentration(string $nesil, string $sensitizationAssessmentFactor, string $consumerExposureLevel, ?string $factor): array
    {
        $ael = DecimalMath::div($nesil, $sensitizationAssessmentFactor);
        $withoutFactor = DecimalMath::mul(DecimalMath::div($ael, DecimalMath::mul($consumerExposureLevel, '1000')), '100');

        return [
            'ael_ug_cm2' => $ael,
            'cmax_without_factor_percent' => $withoutFactor,
            'factor' => $factor,
            'cmax_percent' => $factor === null ? $withoutFactor : DecimalMath::mul($withoutFactor, $factor),
        ];
    }

    /**
     * The factor is applied unless the preset records an expert deviation (enforced when the preset is published).
     *
     * @return array{ael_ug_cm2: string, cmax_without_factor_percent: string, factor: string|null, cmax_percent: string}
     */
    public function forPreset(string $nesil, Qra2Parameter $preset): array
    {
        return $this->maximumConcentration(
            $nesil,
            DecimalMath::of($preset->sensitization_assessment_factor),
            DecimalMath::of($preset->consumer_exposure_level_mg_cm2_day),
            $preset->applies_qra2_factor && $preset->qra2_factor !== null ? DecimalMath::of($preset->qra2_factor) : null,
        );
    }
}
