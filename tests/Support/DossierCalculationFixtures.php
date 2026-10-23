<?php

namespace Tests\Support;

use App\Data\DossierExposureParameters;

/**
 * Inputs for the DIP engine tests. The expected values that go with them are in the phase 3 plan, computed by hand.
 */
final class DossierCalculationFixtures
{
    /**
     * DIP.GA.007's stated inputs: A = 1 g × 10 uses/day, R = 0.01, bw = 60 kg.
     */
    public static function pilotExposure(): DossierExposureParameters
    {
        return new DossierExposureParameters('1', '10', '0.01', '60');
    }
}
