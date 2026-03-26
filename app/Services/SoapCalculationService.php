<?php

namespace App\Services;

use App\SoapFattyAcid;
use App\SoapSap;
use InvalidArgumentException;

class SoapCalculationService
{
    private const GLYCERINE_FROM_NAOH_RATIO = 92.09382 / 119.9922;

    private const GLYCERINE_FROM_KOH_RATIO = 92.09382 / 168.3168;

    /**
     * @param  array<int, array{
     *     name?: string,
     *     weight: float|int|string,
     *     koh_sap_value?: float|int|string|null,
     *     fatty_acid_profile?: array<string, float|int|string>,
     * }>  $oils
     * @param  array{
     *     superfat?: float|int|string,
     *     lye_type?: 'naoh'|'koh'|'dual',
     *     dual_lye_koh_percentage?: float|int|string,
     *     koh_purity_percentage?: float|int|string,
     *     water_mode?: 'percent_of_oils'|'lye_ratio'|'lye_concentration',
     *     water_value?: float|int|string
     * }  $settings
     * @return array{
     *     totals: array{oils_weight: float},
     *     lye: array{
     *         naoh: array{theoretical: float, adjusted: float},
     *         koh: array{theoretical: float, adjusted: float},
     *         water: array{mode: string, value: float, weight: float},
     *         glycerine: array{
     *             naoh_theoretical: float,
     *             naoh_adjusted: float,
     *             koh_theoretical: float,
     *             koh_adjusted: float
     *         },
     *         selected: array{
     *             type: string,
     *             dual_lye_koh_percentage: float,
     *             koh_purity_percentage: float,
     *             naoh_weight: float,
     *             koh_weight: float,
     *             koh_to_weigh: float,
     *             total_active_lye_weight: float,
     *             glycerine_weight: float
     *         },
     *         superfat_percentage: float
     *     },
     *     properties: array{
     *         fatty_acid_profile: array<string, float>,
     *         qualities: array<string, float>
     *     }
     * }
     */
    public function calculate(array $oils, array $settings = []): array
    {
        if ($oils === []) {
            throw new InvalidArgumentException('At least one oil is required for soap calculation.');
        }

        $superfat = $this->normalizeNumericValue($settings['superfat'] ?? 5, 'superfat');

        if ($superfat < 0 || $superfat >= 100) {
            throw new InvalidArgumentException('The superfat percentage must be between 0 and 100.');
        }

        $waterMode = (string) ($settings['water_mode'] ?? 'percent_of_oils');
        $waterValue = $this->normalizeNumericValue($settings['water_value'] ?? 38, 'water value');
        $lyeType = (string) ($settings['lye_type'] ?? 'naoh');
        $dualLyeKohPercentage = $this->normalizeNumericValue($settings['dual_lye_koh_percentage'] ?? 50, 'dual lye KOH percentage');
        $kohPurityPercentage = $this->normalizeNumericValue($settings['koh_purity_percentage'] ?? 100, 'KOH purity percentage');

        $oilsWeight = 0.0;
        $naohTheoretical = 0.0;
        $kohTheoretical = 0.0;
        $fattyAcidTotals = [];

        foreach ($oils as $oil) {
            $weight = $this->normalizeNumericValue($oil['weight'] ?? null, 'oil weight');

            if ($weight <= 0) {
                throw new InvalidArgumentException('Each oil must have a weight greater than zero.');
            }

            $oilsWeight += $weight;
            $kohSapValue = SoapSap::normalizeKohSapInput($this->normalizeNumericValue($oil['koh_sap_value'] ?? 0, 'KOH SAP value'));
            $naohSapValue = SoapSap::deriveNaohFromKoh($kohSapValue);

            $naohTheoretical += $weight * $naohSapValue;
            $kohTheoretical += $weight * $kohSapValue;

            foreach (($oil['fatty_acid_profile'] ?? []) as $key => $value) {
                $normalizedKey = $this->normalizeProfileKey($key);
                $fattyAcidTotals[$normalizedKey] = ($fattyAcidTotals[$normalizedKey] ?? 0.0)
                    + ($weight * $this->normalizeNumericValue($value, "fatty acid profile [{$key}]"));
            }
        }

        $naohAdjusted = $naohTheoretical * (1 - ($superfat / 100));
        $kohAdjusted = $kohTheoretical * (1 - ($superfat / 100));
        $selectedLye = $this->selectedLyeProfile(
            $lyeType,
            $naohAdjusted,
            $kohAdjusted,
            $dualLyeKohPercentage,
            $kohPurityPercentage,
        );

        $fattyAcidProfile = $this->averageProfile($fattyAcidTotals, $oilsWeight);
        $qualities = $this->calculateQualityMetrics($fattyAcidProfile, $oilsWeight, $kohTheoretical);

        return [
            'totals' => [
                'oils_weight' => $this->roundValue($oilsWeight),
            ],
            'lye' => [
                'naoh' => [
                    'theoretical' => $this->roundValue($naohTheoretical),
                    'adjusted' => $this->roundValue($naohAdjusted),
                ],
                'koh' => [
                    'theoretical' => $this->roundValue($kohTheoretical),
                    'adjusted' => $this->roundValue($kohAdjusted),
                ],
                'water' => [
                    'mode' => $waterMode,
                    'value' => $this->roundValue($waterValue),
                    'weight' => $this->roundValue($this->calculateWaterWeight($waterMode, $waterValue, $oilsWeight, $selectedLye['total_active_lye_weight'])),
                ],
                'glycerine' => [
                    'naoh_theoretical' => $this->roundValue($naohTheoretical * self::GLYCERINE_FROM_NAOH_RATIO),
                    'naoh_adjusted' => $this->roundValue($naohAdjusted * self::GLYCERINE_FROM_NAOH_RATIO),
                    'koh_theoretical' => $this->roundValue($kohTheoretical * self::GLYCERINE_FROM_KOH_RATIO),
                    'koh_adjusted' => $this->roundValue($kohAdjusted * self::GLYCERINE_FROM_KOH_RATIO),
                ],
                'selected' => $selectedLye,
                'superfat_percentage' => $this->roundValue($superfat),
            ],
            'properties' => [
                'fatty_acid_profile' => $fattyAcidProfile,
                'qualities' => $qualities,
            ],
        ];
    }

    private function calculateWaterWeight(string $mode, float $value, float $oilsWeight, float $naohAdjusted): float
    {
        return match ($mode) {
            'percent_of_oils' => $oilsWeight * ($value / 100),
            'lye_ratio' => $naohAdjusted * $value,
            'lye_concentration' => $this->waterFromLyeConcentration($value, $naohAdjusted),
            default => throw new InvalidArgumentException('Unsupported water mode.'),
        };
    }

    /**
     * @return array{
     *     type: string,
     *     dual_lye_koh_percentage: float,
     *     koh_purity_percentage: float,
     *     naoh_weight: float,
     *     koh_weight: float,
     *     koh_to_weigh: float,
     *     total_active_lye_weight: float,
     *     glycerine_weight: float
     * }
     */
    private function selectedLyeProfile(
        string $lyeType,
        float $naohAdjusted,
        float $kohAdjusted,
        float $dualLyeKohPercentage,
        float $kohPurityPercentage,
    ): array {
        if (! in_array($lyeType, ['naoh', 'koh', 'dual'], true)) {
            throw new InvalidArgumentException('Unsupported lye type.');
        }

        if ($dualLyeKohPercentage < 0 || $dualLyeKohPercentage > 100) {
            throw new InvalidArgumentException('Dual lye KOH percentage must be between 0 and 100.');
        }

        if ($kohPurityPercentage <= 0 || $kohPurityPercentage > 100) {
            throw new InvalidArgumentException('KOH purity percentage must be between 0 and 100.');
        }

        $kohRatio = match ($lyeType) {
            'naoh' => 0.0,
            'koh' => 1.0,
            'dual' => $dualLyeKohPercentage / 100,
        };

        $naohRatio = 1 - $kohRatio;
        $selectedNaohWeight = $naohAdjusted * $naohRatio;
        $selectedKohWeight = $kohAdjusted * $kohRatio;

        return [
            'type' => $lyeType,
            'dual_lye_koh_percentage' => $this->roundValue($dualLyeKohPercentage),
            'koh_purity_percentage' => $this->roundValue($kohPurityPercentage),
            'naoh_weight' => $this->roundValue($selectedNaohWeight),
            'koh_weight' => $this->roundValue($selectedKohWeight),
            'koh_to_weigh' => $this->roundValue(
                $selectedKohWeight <= 0
                    ? 0
                    : SoapSap::adjustKohForPurity($selectedKohWeight, $kohPurityPercentage)
            ),
            'total_active_lye_weight' => $this->roundValue($selectedNaohWeight + $selectedKohWeight),
            'glycerine_weight' => $this->roundValue(
                ($selectedNaohWeight * self::GLYCERINE_FROM_NAOH_RATIO)
                + ($selectedKohWeight * self::GLYCERINE_FROM_KOH_RATIO)
            ),
        ];
    }

    /**
     * @param  array<string, float>  $fattyAcidProfile
     * @param  array<string, float>  $qualityProfile
     * @return array<string, float>
     */
    private function calculateQualityMetrics(array $fattyAcidProfile, float $oilsWeight, float $kohTheoretical): array
    {
        $lauric = $fattyAcidProfile['lauric'] ?? 0.0;
        $myristic = $fattyAcidProfile['myristic'] ?? 0.0;
        $palmitic = $fattyAcidProfile['palmitic'] ?? 0.0;
        $stearic = $fattyAcidProfile['stearic'] ?? 0.0;
        $ricinoleic = $fattyAcidProfile['ricinoleic'] ?? 0.0;
        $oleic = $fattyAcidProfile['oleic'] ?? 0.0;
        $linoleic = $fattyAcidProfile['linoleic'] ?? 0.0;
        $linolenic = $fattyAcidProfile['linolenic'] ?? 0.0;

        $iodine = $this->calculateIodineValue($fattyAcidProfile);
        $ins = $oilsWeight <= 0 ? 0.0 : (($kohTheoretical / $oilsWeight) * 1000) - $iodine;

        return [
            'hardness' => $this->roundValue($lauric + $myristic + $palmitic + $stearic),
            'cleansing' => $this->roundValue($lauric + $myristic),
            'conditioning' => $this->roundValue($oleic + $ricinoleic + $linoleic + $linolenic),
            'bubbly' => $this->roundValue($lauric + $myristic + $ricinoleic),
            'creamy' => $this->roundValue($palmitic + $stearic + $ricinoleic),
            'iodine' => $this->roundValue($iodine),
            'ins' => $this->roundValue($ins),
        ];
    }

    /**
     * @param  array<string, float>  $fattyAcidProfile
     */
    private function calculateIodineValue(array $fattyAcidProfile): float
    {
        $iodine = 0.0;

        foreach (SoapFattyAcid::coreSet() as $fattyAcid) {
            $iodine += ($fattyAcidProfile[$fattyAcid->value] ?? 0.0) * $fattyAcid->iodineFactor();
        }

        return $this->roundValue($iodine);
    }

    private function waterFromLyeConcentration(float $concentrationPercentage, float $naohAdjusted): float
    {
        if ($concentrationPercentage <= 0 || $concentrationPercentage >= 100) {
            throw new InvalidArgumentException('Lye concentration must be between 0 and 100.');
        }

        $concentration = $concentrationPercentage / 100;

        return ($naohAdjusted / $concentration) - $naohAdjusted;
    }

    /**
     * @param  array<string, float>  $totals
     * @return array<string, float>
     */
    private function averageProfile(array $totals, float $oilsWeight): array
    {
        if ($oilsWeight <= 0) {
            return [];
        }

        $averages = [];

        foreach ($totals as $key => $total) {
            $averages[$key] = $this->roundValue($total / $oilsWeight);
        }

        ksort($averages);

        return $averages;
    }

    private function normalizeNumericValue(float|int|string|null $value, string $label): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        if (! is_numeric($value)) {
            throw new InvalidArgumentException("The {$label} must be numeric.");
        }

        return (float) $value;
    }

    private function normalizeProfileKey(string $key): string
    {
        return strtolower(str_replace([' ', '-'], '_', trim($key)));
    }

    private function roundValue(float $value): float
    {
        return round($value, 4);
    }
}
