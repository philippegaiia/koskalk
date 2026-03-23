<?php

namespace App;

final class SoapSap
{
    public const NAOH_FROM_KOH_RATIO = 0.713;

    public const PROFESSIONAL_KOH_SAP_DIVISOR = 1000;

    public const KOH_90_PURITY_COEFFICIENT = 1 / 0.9;

    public static function normalizeKohSapInput(float $kohSapValue): float
    {
        return $kohSapValue > 1
            ? $kohSapValue / self::PROFESSIONAL_KOH_SAP_DIVISOR
            : $kohSapValue;
    }

    public static function deriveNaohFromKoh(float $kohSapValue): float
    {
        return self::normalizeKohSapInput($kohSapValue) * self::NAOH_FROM_KOH_RATIO;
    }

    public static function deriveKohFromNaoh(float $naohSapValue): float
    {
        return $naohSapValue / self::NAOH_FROM_KOH_RATIO;
    }

    public static function adjustKohForPurity(float $pureKohWeight, float $purityPercentage = 90): float
    {
        return $pureKohWeight / ($purityPercentage / 100);
    }
}
