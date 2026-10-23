<?php

namespace App\Services\Dossiers\Calculation;

use App\Data\DossierExposureParameters;
use App\Enums\NoaelRoute;
use App\Support\DecimalMath;

/**
 * SCCS exposure arithmetic for the DIP (spec §4.2), at full precision.
 */
class ExposureCalculator
{
    /**
     * SED [mg/kg bw/day] = A [g/day] × 1000 × C/100 × DAp/100 × R ÷ bw.
     */
    public function systemicExposureDose(string $concentrationPercent, string $dermalAbsorptionPercent, DossierExposureParameters $exposure): string
    {
        return DecimalMath::div(
            DecimalMath::mul(
                $exposure->dailyAmountGrams(),
                '1000',
                DecimalMath::div($concentrationPercent, '100'),
                DecimalMath::div($dermalAbsorptionPercent, '100'),
                $exposure->retentionFactor,
            ),
            $exposure->bodyWeightKg,
        );
    }

    /**
     * Impurities are expressed in ppm (1 % = 10,000 ppm) and their dose in µg/kg bw/day.
     */
    public function impurityExposureDoseMicrograms(string $ppm, string $dermalAbsorptionPercent, DossierExposureParameters $exposure): string
    {
        return DecimalMath::mul(
            $this->systemicExposureDose(DecimalMath::div($ppm, '10000'), $dermalAbsorptionPercent, $exposure),
            '1000',
        );
    }

    /**
     * NOAELsys = NOAEL × oral bioavailability for an oral study. A dermal NOAEL is not converted (decision 10): the
     * study's own absorption is not recorded, and reusing the product DAp would cancel it out of the MoS. An inhalation
     * NOAEL is not converted either.
     */
    public function systemicNoael(string $noael, NoaelRoute $route, string $oralBioavailabilityPercent): ?string
    {
        return $route === NoaelRoute::Oral
            ? DecimalMath::mul($noael, DecimalMath::div($oralBioavailabilityPercent, '100'))
            : null;
    }

    /**
     * MoS = NOAELsys ÷ SED; none when there is no exposure.
     */
    public function marginOfSafety(string $systemicNoael, string $systemicExposureDose): ?string
    {
        return DecimalMath::isPositive($systemicExposureDose)
            ? DecimalMath::div($systemicNoael, $systemicExposureDose)
            : null;
    }
}
