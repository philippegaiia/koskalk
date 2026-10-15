<?php

namespace App;

final class SoapSap
{
    public const NAOH_FROM_KOH_RATIO = 0.713;

    public const PROFESSIONAL_KOH_SAP_DIVISOR = 1000;

    public const KOH_90_PURITY_COEFFICIENT = 1 / 0.9;

    /**
     * Residual water assumed in cured soap when no platform value is set. Saved
     * formulas created before the value became configurable used this basis.
     */
    public const DEFAULT_CURED_WATER_PERCENTAGE = 11.0;

    public const MAX_CURED_WATER_PERCENTAGE = 30.0;

    /**
     * Resolve a stored or submitted cured-water percentage, falling back to the
     * historical default when it is missing or outside the supported range.
     */
    public static function curedWaterPercentage(mixed $value): float
    {
        if (! is_numeric($value)) {
            return self::DEFAULT_CURED_WATER_PERCENTAGE;
        }

        $percentage = (float) $value;

        return $percentage >= 0 && $percentage <= self::MAX_CURED_WATER_PERCENTAGE
            ? $percentage
            : self::DEFAULT_CURED_WATER_PERCENTAGE;
    }

    /**
     * Cured soap keeps every non-water mass plus the residual water share.
     */
    public static function curedWeight(float $nonWaterWeight, float $curedWaterPercentage): float
    {
        return $nonWaterWeight / (1 - ($curedWaterPercentage / 100));
    }

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
