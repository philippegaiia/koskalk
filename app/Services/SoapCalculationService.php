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
     *         fatty_acid_groups: array<string, float>,
     *         superfat_effects: array<string, float>,
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
        $fattyAcidGroups = $this->deriveFattyAcidGroups($fattyAcidProfile);
        $superfatEffects = $this->calculateSuperfatEffects($fattyAcidProfile, $fattyAcidGroups, $superfat);
        $qualities = $this->calculateQualityMetrics($fattyAcidProfile, $oilsWeight, $kohTheoretical, $superfat);

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
                'fatty_acid_groups' => $fattyAcidGroups,
                'superfat_effects' => $superfatEffects,
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
     * @return array<string, float>
     */
    private function calculateQualityMetrics(array $fattyAcidProfile, float $oilsWeight, float $kohTheoretical, float $superfat): array
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
        $groups = $this->deriveFattyAcidGroups($fattyAcidProfile);
        $superfatEffects = $this->calculateSuperfatEffects($fattyAcidProfile, $groups, $superfat);
        $effectiveCleansing = $superfatEffects['effective_cleansing'];
        $cleansingStrength = max(0.0, min(100.0, $effectiveCleansing));
        $hs = $groups['hs'] ?? 0.0;
        $mu = $groups['mu'] ?? 0.0;
        $pu = $groups['pu'] ?? 0.0;
        $sp = $groups['sp'] ?? 0.0;
        $vs = $groups['vs'] ?? 0.0;

        return [
            'hardness' => $this->roundValue($lauric + $myristic + $palmitic + $stearic),
            'cleansing' => $this->roundValue($lauric + $myristic),
            'conditioning' => $this->roundValue($oleic + $ricinoleic + $linoleic + $linolenic),
            'bubbly' => $this->roundValue($lauric + $myristic + $ricinoleic),
            'creamy' => $this->roundValue($palmitic + $stearic + $ricinoleic),
            'unmolding_firmness' => $this->roundValue(max(0.0, min(100.0, (0.85 * $vs) + (0.95 * $hs) - (0.40 * $mu) + 18))),
            'cured_hardness' => $this->roundValue(max(0.0, min(100.0, (1.15 * $hs) + (0.55 * $vs) + (0.20 * $mu) - (0.50 * $pu) + 8))),
            'longevity' => $this->roundValue(max(0.0, min(100.0, (0.85 * $hs) + (0.18 * $vs) - (0.35 * $sp) - (0.30 * $pu) + 20))),
            'cleansing_strength' => $this->roundValue($cleansingStrength),
            'mildness' => $this->roundValue(max(0.0, min(100.0, 78 - (1.00 * $cleansingStrength) + (0.18 * $mu) - (0.12 * $pu)))),
            'bubble_volume' => $this->roundValue(max(0.0, min(100.0, (1.05 * $vs) + (1.05 * $sp) - (0.30 * $hs)))),
            'creamy_lather' => $this->roundValue(max(0.0, min(100.0, (0.95 * $hs) + (0.90 * $sp) + (0.16 * $mu) - (0.15 * $vs)))),
            'lather_stability' => $this->roundValue(max(0.0, min(100.0, (1.00 * $sp) + (0.68 * $hs) + (0.28 * $vs)))),
            'conditioning_feel' => $this->roundValue(max(0.0, min(100.0, (0.35 * $mu) + (0.15 * $pu) + (0.15 * $sp) - (0.45 * $cleansingStrength) + 35))),
            'dos_risk' => $this->roundValue(max(0.0, min(100.0, (1.35 * $pu)))),
            'slime_risk' => $this->roundValue(max(0.0, min(100.0, (0.72 * $mu) - (0.42 * $vs) - (0.36 * $hs) + (($mu > 65 && $vs < 12 && $hs < 20) ? 8 : 0)))),
            'cure_speed' => $this->roundValue(max(0.0, min(100.0, (0.75 * $vs) + (0.80 * $hs) - (0.52 * $mu) + 20))),
            'iodine' => $this->roundValue($iodine),
            'ins' => $this->roundValue($ins),
        ];
    }

    /**
     * @param  array<string, float>  $fattyAcidProfile
     * @return array<string, float>
     */
    private function deriveFattyAcidGroups(array $fattyAcidProfile): array
    {
        $caprylic = $fattyAcidProfile['caprylic'] ?? 0.0;
        $capric = $fattyAcidProfile['capric'] ?? 0.0;
        $lauric = $fattyAcidProfile['lauric'] ?? 0.0;
        $myristic = $fattyAcidProfile['myristic'] ?? 0.0;
        $palmitic = $fattyAcidProfile['palmitic'] ?? 0.0;
        $stearic = $fattyAcidProfile['stearic'] ?? 0.0;
        $arachidic = $fattyAcidProfile['arachidic'] ?? 0.0;
        $behenic = $fattyAcidProfile['behenic'] ?? 0.0;
        $lignoceric = $fattyAcidProfile['lignoceric'] ?? 0.0;
        $oleic = $fattyAcidProfile['oleic'] ?? 0.0;
        $palmitoleic = $fattyAcidProfile['palmitoleic'] ?? 0.0;
        $gondoic = $fattyAcidProfile['gondoic'] ?? 0.0;
        $erucic = $fattyAcidProfile['erucic'] ?? 0.0;
        $nervonic = $fattyAcidProfile['nervonic'] ?? 0.0;
        $linoleic = $fattyAcidProfile['linoleic'] ?? 0.0;
        $linolenic = $fattyAcidProfile['linolenic'] ?? 0.0;
        $gammaLinolenic = $fattyAcidProfile['gamma_linolenic'] ?? 0.0;
        $punicic = $fattyAcidProfile['punicic'] ?? 0.0;
        $ricinoleic = $fattyAcidProfile['ricinoleic'] ?? 0.0;

        $vs = $caprylic + $capric + $lauric + $myristic;
        $hs = $palmitic + $stearic + $arachidic + $behenic + $lignoceric;
        $mu = $oleic + $palmitoleic + $gondoic + $erucic + $nervonic;
        $pu = $linoleic + $linolenic + $gammaLinolenic + $punicic;
        $sp = $ricinoleic;

        return [
            'vs' => $this->roundValue($vs),
            'hs' => $this->roundValue($hs),
            'mu' => $this->roundValue($mu),
            'pu' => $this->roundValue($pu),
            'sp' => $this->roundValue($sp),
            'sat' => $this->roundValue($vs + $hs),
            'unsat' => $this->roundValue($mu + $pu + $sp),
        ];
    }

    /**
     * @param  array<string, float>  $fattyAcidProfile
     * @param  array<string, float>  $fattyAcidGroups
     * @return array<string, float>
     */
    private function calculateSuperfatEffects(array $fattyAcidProfile, array $fattyAcidGroups, float $superfat): array
    {
        $lauric = $fattyAcidProfile['lauric'] ?? 0.0;
        $myristic = $fattyAcidProfile['myristic'] ?? 0.0;
        $capric = $fattyAcidProfile['capric'] ?? 0.0;
        $caprylic = $fattyAcidProfile['caprylic'] ?? 0.0;
        $hs = $fattyAcidGroups['hs'] ?? 0.0;

        $baseCleansingPotential = $this->roundValue(
            max(0.0, (1.55 * ($lauric + $myristic)) + (1.00 * $capric) + (0.65 * $caprylic) + (0.20 * ($fattyAcidGroups['vs'] ?? 0.0)) - (0.10 * $hs))
        );

        $superfatBuffer = $this->roundValue(
            max(0.0, $superfat * (0.35 + (0.020 * $baseCleansingPotential)))
        );

        $effectiveCleansing = $this->roundValue(max(0.0, $baseCleansingPotential - $superfatBuffer));
        $dosRiskModifier = $this->roundValue(($fattyAcidGroups['pu'] ?? 0.0) * ($superfat / 100));

        return [
            'base_cleansing_potential' => $baseCleansingPotential,
            'superfat_buffer' => $superfatBuffer,
            'effective_cleansing' => $effectiveCleansing,
            'dos_risk_modifier' => $dosRiskModifier,
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
