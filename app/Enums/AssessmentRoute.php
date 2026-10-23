<?php

namespace App\Enums;

/**
 * How a DIP row is concluded (spec §4.2). Each value is also the key of the indicator that must pass.
 */
enum AssessmentRoute: string
{
    case MarginOfSafety = 'mos';
    case Cmax = 'cmax';
    case Adi = 'adi';
    case Ttc = 'ttc';
    case Qra2 = 'qra2';
}
