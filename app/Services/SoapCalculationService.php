<?php

namespace App\Services;

use App\Models\FattyAcid;
use App\SoapSap;
use InvalidArgumentException;

class SoapCalculationService
{
    public const QUALITY_MODEL_VERSION = '2026-09-13';

    /** Weighted C6–C14 content of the documented zero-superfat reference profile. */
    private const CLEANSING_REFERENCE = 76.62;

    private const GLYCERINE_FROM_NAOH_RATIO = 92.09382 / 119.9922;

    private const GLYCERINE_FROM_KOH_RATIO = 92.09382 / 168.3168;

    /** @var array<string, float>|null */
    private ?array $iodineFactorsCache = null;

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
        $waterMode = (string) ($settings['water_mode'] ?? 'percent_of_oils');
        $waterValue = $this->normalizeNumericValue($settings['water_value'] ?? 38, 'water value');
        $lyeType = (string) ($settings['lye_type'] ?? 'naoh');
        $dualLyeKohPercentage = $this->normalizeNumericValue($settings['dual_lye_koh_percentage'] ?? 50, 'dual lye KOH percentage');
        $kohPurityPercentage = $this->normalizeNumericValue($settings['koh_purity_percentage'] ?? 100, 'KOH purity percentage');
        $soapContext = $this->deriveSoapContext($lyeType, $dualLyeKohPercentage);

        $this->validateSuperfatForContext($superfat, $soapContext);

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
        $waterWeight = $this->calculateWaterWeight($waterMode, $waterValue, $oilsWeight, $selectedLye['total_active_lye_weight']);
        $waterProcessModifiers = $this->calculateWaterProcessModifiers($waterWeight, $oilsWeight, $selectedLye['total_active_lye_weight']);

        $fattyAcidProfile = $this->averageProfile($fattyAcidTotals, $oilsWeight);
        $fattyAcidGroups = $this->deriveFattyAcidGroups($fattyAcidProfile);
        $superfatEffects = $this->calculateSuperfatEffects($fattyAcidProfile, $fattyAcidGroups, $superfat);
        $qualities = $this->calculateQualityMetrics($fattyAcidProfile, $oilsWeight, $kohTheoretical, $superfat, $superfatEffects, $waterProcessModifiers, $soapContext);
        $qualityApplicability = $this->deriveQualityApplicability($qualities, $soapContext, $superfat);
        $warnings = $this->buildSoapWarnings($qualities, $fattyAcidGroups, $superfat, $soapContext);

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
                    'weight' => $this->roundValue($waterWeight),
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
            'soap_context' => $soapContext,
            'properties' => [
                'quality_model_version' => self::QUALITY_MODEL_VERSION,
                'fatty_acid_profile' => $fattyAcidProfile,
                'fatty_acid_groups' => $fattyAcidGroups,
                'superfat_effects' => $superfatEffects,
                'qualities' => $qualities,
                'quality_applicability' => $qualityApplicability,
                'warnings' => $warnings,
            ],
        ];
    }

    /**
     * Calculate the theoretical cured-soap mass attributable to one oil.
     *
     * The saponified oil portion gains its selected active lye mass and loses
     * the glycerine produced by that same lye reaction. The result can then
     * be assigned to the oil's soap-specific INCI output.
     *
     * @param  array{
     *     superfat?: float|int|string,
     *     lye_type?: 'naoh'|'koh'|'dual',
     *     dual_lye_koh_percentage?: float|int|string,
     *     koh_purity_percentage?: float|int|string
     * }  $settings
     * @return array{
     *     saponified_weight: float,
     *     selected_naoh_weight: float,
     *     selected_koh_weight: float,
     *     koh_to_weigh: float,
     *     glycerine_weight: float,
     *     naoh_soap_salt_weight: float,
     *     koh_soap_salt_weight: float,
     *     soap_salt_weight: float
     * }
     */
    public function calculateOilReactionProducts(float $oilWeight, float $kohSapValue, array $settings = []): array
    {
        if ($oilWeight <= 0) {
            throw new InvalidArgumentException('Oil weight must be greater than zero.');
        }

        $superfat = $this->normalizeNumericValue($settings['superfat'] ?? 5, 'superfat');
        $lyeType = (string) ($settings['lye_type'] ?? 'naoh');
        $dualLyeKohPercentage = $this->normalizeNumericValue($settings['dual_lye_koh_percentage'] ?? 50, 'dual lye KOH percentage');
        $kohPurityPercentage = $this->normalizeNumericValue($settings['koh_purity_percentage'] ?? 100, 'KOH purity percentage');
        $soapContext = $this->deriveSoapContext($lyeType, $dualLyeKohPercentage);

        $this->validateSuperfatForContext($superfat, $soapContext);

        $normalizedKohSapValue = SoapSap::normalizeKohSapInput($kohSapValue);
        $naohSapValue = SoapSap::deriveNaohFromKoh($normalizedKohSapValue);
        $superfatMultiplier = 1 - ($superfat / 100);
        $naohAdjusted = $oilWeight * $naohSapValue * $superfatMultiplier;
        $kohAdjusted = $oilWeight * $normalizedKohSapValue * $superfatMultiplier;
        $selectedLye = $this->selectedLyeProfile(
            $lyeType,
            $naohAdjusted,
            $kohAdjusted,
            $dualLyeKohPercentage,
            $kohPurityPercentage,
        );
        $kohRatio = match ($lyeType) {
            'naoh' => 0.0,
            'koh' => 1.0,
            'dual' => $dualLyeKohPercentage / 100,
        };
        $naohRatio = 1 - $kohRatio;
        $saponifiedWeight = $oilWeight * $superfatMultiplier;
        $naohGlycerineWeight = $selectedLye['naoh_weight'] * self::GLYCERINE_FROM_NAOH_RATIO;
        $kohGlycerineWeight = $selectedLye['koh_weight'] * self::GLYCERINE_FROM_KOH_RATIO;
        $naohSoapSaltWeight = ($saponifiedWeight * $naohRatio)
            + $selectedLye['naoh_weight']
            - $naohGlycerineWeight;
        $kohSoapSaltWeight = ($saponifiedWeight * $kohRatio)
            + $selectedLye['koh_weight']
            - $kohGlycerineWeight;

        return [
            'saponified_weight' => $this->roundValue($saponifiedWeight),
            'selected_naoh_weight' => $selectedLye['naoh_weight'],
            'selected_koh_weight' => $selectedLye['koh_weight'],
            'koh_to_weigh' => $selectedLye['koh_to_weigh'],
            'glycerine_weight' => $this->roundValue($naohGlycerineWeight + $kohGlycerineWeight),
            'naoh_soap_salt_weight' => $this->roundValue($naohSoapSaltWeight),
            'koh_soap_salt_weight' => $this->roundValue($kohSoapSaltWeight),
            'soap_salt_weight' => $this->roundValue($naohSoapSaltWeight + $kohSoapSaltWeight),
        ];
    }

    private function deriveSoapContext(string $lyeType, float $dualLyeKohPercentage): array
    {
        if (! in_array($lyeType, ['naoh', 'koh', 'dual'], true)) {
            throw new InvalidArgumentException('Unsupported lye type.');
        }

        if ($dualLyeKohPercentage < 0 || $dualLyeKohPercentage > 100) {
            throw new InvalidArgumentException('Dual lye KOH percentage must be between 0 and 100.');
        }

        $kohPercentage = match ($lyeType) {
            'naoh' => 0.0,
            'koh' => 100.0,
            'dual' => $dualLyeKohPercentage,
        };

        $type = match (true) {
            $kohPercentage <= 20 => 'bar',
            $kohPercentage <= 40 => 'hybrid',
            $kohPercentage <= 60 => 'soft_or_liquid',
            default => 'liquid',
        };

        $barContext = match (true) {
            $kohPercentage <= 20 => 1.0,
            $kohPercentage >= 60 => 0.0,
            default => (60.0 - $kohPercentage) / 40.0,
        };
        $liquidContext = 1.0 - $barContext;

        return [
            'type' => $type,
            'koh_percentage' => $this->roundValue($kohPercentage),
            'bar_context' => $this->roundValue($barContext),
            'liquid_context' => $this->roundValue($liquidContext),
            'bar_metrics_applicable' => $kohPercentage <= 40,
        ];
    }

    /**
     * @param  array<string, mixed>  $soapContext
     */
    private function validateSuperfatForContext(float $superfat, array $soapContext): void
    {
        if ($superfat >= 100) {
            throw new InvalidArgumentException('The superfat percentage must be below 100.');
        }

        if ($superfat < 0 && ! $this->isLiquidOrHighKohContext($soapContext)) {
            throw new InvalidArgumentException('Negative superfat is only supported for liquid or high-KOH soap workflows.');
        }

        if ($superfat < -25) {
            throw new InvalidArgumentException('Liquid soap negative superfat must not be below -25%.');
        }
    }

    /**
     * @param  array<string, float>  $qualities
     * @param  array<string, mixed>  $soapContext
     * @return array<string, array<string, bool|float|string>>
     */
    private function deriveQualityApplicability(array $qualities, array $soapContext, float $superfat): array
    {
        $barOnlyMetrics = [
            'unmolding_firmness',
            'cured_hardness',
            'longevity',
            'cure_speed',
            'slime_risk',
            'dos_risk',
            'shrinkage_risk',
        ];
        $skinUseMetrics = [
            'cleansing_strength',
            'mildness',
            'conditioning_feel',
            'bubble_volume',
            'creamy_lather',
            'lather_stability',
        ];
        $barMetricsApplicable = (bool) ($soapContext['bar_metrics_applicable'] ?? true);
        $barContext = (float) ($soapContext['bar_context'] ?? 1.0);
        $applicability = [];

        foreach ($qualities as $quality => $_score) {
            if ($superfat < 0 && in_array($quality, ['cleansing_strength', 'mildness', 'conditioning_feel'], true)) {
                $applicability[$quality] = [
                    'applies' => false,
                    'confidence' => 0.0,
                    'display' => 'not_applicable',
                    'reason' => 'Finished-soap skin feel requires a completed neutralization workflow.',
                ];

                continue;
            }

            if (! $barMetricsApplicable && in_array($quality, $barOnlyMetrics, true)) {
                $applicability[$quality] = [
                    'applies' => false,
                    'confidence' => 0.0,
                    'display' => 'not_applicable',
                    'reason' => 'This bar-soap metric is not meaningful for liquid/high-KOH soap.',
                ];

                continue;
            }

            if (($soapContext['koh_percentage'] ?? 0.0) > 0 && in_array($quality, [...$skinUseMetrics, ...$barOnlyMetrics], true)) {
                $applicability[$quality] = [
                    'applies' => true,
                    'confidence' => $this->roundValue(max(0.35, min(0.75, $barContext))),
                    'display' => 'tendency',
                ];

                continue;
            }

            $applicability[$quality] = [
                'applies' => true,
                'confidence' => 1.0,
                'display' => 'score',
            ];
        }

        return $applicability;
    }

    /**
     * @param  array<string, float>  $qualities
     * @param  array<string, float>  $fattyAcidGroups
     * @param  array<string, mixed>  $soapContext
     * @return array<int, string>
     */
    private function buildSoapWarnings(array $qualities, array $fattyAcidGroups, float $superfat, array $soapContext): array
    {
        $warnings = [];

        if ($this->isLiquidOrHighKohContext($soapContext)) {
            $warnings[] = 'high_koh_context_process_dependent';
        }

        if ($this->isLiquidOrHighKohContext($soapContext) && $superfat < 0) {
            $warnings[] = 'negative_superfat_requires_neutralization_and_ph_control';
        }

        if ($this->isLiquidOrHighKohContext($soapContext) && $superfat > 3) {
            $warnings[] = 'positive_liquid_superfat_may_cloud_or_separate';
        }

        if (($fattyAcidGroups['pu'] ?? 0.0) > 15) {
            $warnings[] = 'high_polyunsaturated_dos_risk';
        }

        if (($fattyAcidGroups['pu'] ?? 0.0) > 20) {
            $warnings[] = 'very_high_polyunsaturated_dos_risk';
        }

        return array_values(array_unique($warnings));
    }

    /**
     * @param  array<string, mixed>  $soapContext
     */
    private function isLiquidOrHighKohContext(array $soapContext): bool
    {
        return in_array($soapContext['type'] ?? 'bar', ['soft_or_liquid', 'liquid'], true);
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
    private function calculateQualityMetrics(
        array $fattyAcidProfile,
        float $oilsWeight,
        float $kohTheoretical,
        float $superfat,
        array $superfatEffects,
        array $waterProcessModifiers,
        array $soapContext,
    ): array {
        $caprylic = $fattyAcidProfile['caprylic'] ?? 0.0;
        $capric = $fattyAcidProfile['capric'] ?? 0.0;
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
        $effectiveCleansing = $superfatEffects['effective_cleansing'];
        $superfatSoftening = $superfatEffects['superfat_softening'] ?? 0.0;
        $superfatLatherPenalty = $superfatEffects['superfat_lather_penalty'] ?? 0.0;
        $processFirmnessModifier = $waterProcessModifiers['firmness'] ?? 0.0;
        $processCureModifier = $waterProcessModifiers['cure_speed'] ?? 0.0;
        $processHardnessModifier = $waterProcessModifiers['cured_hardness'] ?? 0.0;
        $cleansingStrength = max(0.0, min(100.0, $effectiveCleansing));
        $hs = $groups['hs'] ?? 0.0;
        $mu = $groups['mu'] ?? 0.0;
        $pu = $groups['pu'] ?? 0.0;
        $sp = $groups['sp'] ?? 0.0;
        $vs = $groups['vs'] ?? 0.0;
        $caprylicCapric = $caprylic + $capric;
        $lauricMyristic = $lauric + $myristic;
        $solubleBubbleFats = $lauricMyristic + (0.55 * $caprylicCapric);
        $ricinoleicSupport = min($ricinoleic, 8.0);
        $ricinoleicExcess = max(0.0, $ricinoleic - 10.0);
        $highHsSolubilityDrag = max(0.0, $hs - 25.0);
        $moderateHardSaturatedStructure = max(0.0, min($hs - 22.0, 34.0 - $hs, 6.0));
        $cureStructureBonus = 0.90 * $moderateHardSaturatedStructure;
        $highOleicStructure = 45.0 * $this->smoothTransition($oleic, 65.0, 75.0)
            * (1.0 - $this->smoothTransition($vs, 0.0, 15.0));
        $kohRatio = (float) $soapContext['koh_percentage'] / 100.0;
        $barStructureFactor = 1.0 - (0.60 * $kohRatio);
        $kohLatherSupport = 0.20 * $hs * $kohRatio;
        $castileSlimeModifier = 8.0 * $this->smoothTransition($mu, 60.0, 70.0)
            * (1.0 - $this->smoothTransition($vs, 7.0, 17.0))
            * (1.0 - $this->smoothTransition($hs, 15.0, 25.0));

        return [
            'hardness' => $this->roundValue($lauric + $myristic + $palmitic + $stearic),
            'cleansing' => $this->roundValue($lauric + $myristic),
            'conditioning' => $this->roundValue($oleic + $ricinoleic + $linoleic + $linolenic),
            'bubbly' => $this->roundValue($lauric + $myristic + $ricinoleic),
            'creamy' => $this->roundValue($palmitic + $stearic + $ricinoleic),
            'unmolding_firmness' => $this->roundValue(max(0.0, min(100.0, ((0.85 * $vs) + (1.25 * $hs) - (0.25 * $mu) - $superfatSoftening + $processFirmnessModifier + 18) * $barStructureFactor))),
            'cured_hardness' => $this->roundValue(max(0.0, min(100.0, ((1.35 * $hs) + (0.55 * $vs) + (0.20 * $mu) - (0.50 * $pu) - (0.45 * $superfatSoftening) + $processHardnessModifier + $highOleicStructure + 8) * $barStructureFactor))),
            'longevity' => $this->roundValue(max(0.0, min(100.0, ((0.85 * $hs) + (0.18 * $vs) - (0.35 * $sp) - (0.30 * $pu) - (0.70 * $superfatSoftening) + 20) * $barStructureFactor))),
            'cleansing_strength' => $this->roundValue($cleansingStrength),
            'mildness' => $this->roundValue(max(0.0, min(100.0, 78 - (1.00 * $cleansingStrength) + (0.18 * $mu) - (0.12 * $pu) + (0.30 * $superfatSoftening)))),
            'bubble_volume' => $this->roundValue(max(0.0, min(100.0, 8 + (1.15 * $lauricMyristic) + (0.55 * $caprylicCapric) + (0.85 * $ricinoleicSupport) - (0.06 * $highHsSolubilityDrag) - (0.80 * $ricinoleicExcess) - $superfatLatherPenalty + $kohLatherSupport))),
            'creamy_lather' => $this->roundValue(max(0.0, min(100.0, 4 + (0.95 * $hs) + (0.18 * $stearic) + (0.75 * $ricinoleicSupport) + (0.16 * $mu) - (0.10 * $vs) - (0.35 * $superfatLatherPenalty) - (0.35 * $ricinoleicExcess)))),
            'lather_stability' => $this->roundValue(max(0.0, min(100.0, 8 + (0.75 * $hs) + (0.20 * $solubleBubbleFats) + (1.40 * $ricinoleicSupport) - (0.45 * $ricinoleicExcess) - $superfatLatherPenalty))),
            'conditioning_feel' => $this->roundValue(max(0.0, min(100.0, (0.35 * $mu) + (0.15 * min($pu, 15.0)) + (0.15 * $sp) - (0.45 * $cleansingStrength) + (0.20 * $superfatSoftening) + 35))),
            'dos_risk' => $this->roundValue($this->calculateDosRisk($pu, $superfat, $iodine)),
            'slime_risk' => $this->roundValue(max(0.0, min(100.0, ((0.72 * $mu) - (0.42 * $vs) - (0.36 * $hs) + (0.25 * $superfatSoftening) + $castileSlimeModifier) * (1.0 - (0.75 * $kohRatio))))),
            'shrinkage_risk' => $this->roundValue($waterProcessModifiers['shrinkage_risk']),
            'cure_speed' => $this->roundValue(max(0.0, min(100.0, (0.75 * $vs) + (0.80 * $hs) - (0.52 * $mu) - (0.55 * $superfatSoftening) + $processCureModifier + $cureStructureBonus + 20))),
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
        $caproic = $fattyAcidProfile['caproic'] ?? 0.0;
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

        $vs = $caproic + $caprylic + $capric + $lauric + $myristic;
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
        $caproic = $fattyAcidProfile['caproic'] ?? 0.0;
        $weightedCleansingAcids = max(0.0, $lauric + $myristic + $capric + (0.65 * $caprylic) + (0.35 * $caproic));
        $baseCleansingPotential = 100.0 * min(1.0, ($weightedCleansingAcids / self::CLEANSING_REFERENCE) ** 0.62);
        $effectiveCleansing = $baseCleansingPotential * (1.0 - (max(0.0, $superfat) / 100.0)) ** 3.9;
        $superfatBuffer = $baseCleansingPotential - $effectiveCleansing;
        $dosRiskModifier = $this->roundValue(($fattyAcidGroups['pu'] ?? 0.0) * ($superfat / 100));
        $superfatSoftening = $this->roundValue(max(0.0, ($superfat - 2.0) * 0.80));
        $superfatLatherPenalty = $this->roundValue(max(0.0, ($superfat - 5.0) * 0.65));

        return [
            'base_cleansing_potential' => $this->roundValue($baseCleansingPotential),
            'superfat_buffer' => $this->roundValue($superfatBuffer),
            'effective_cleansing' => $this->roundValue($effectiveCleansing),
            'dos_risk_modifier' => $dosRiskModifier,
            'superfat_softening' => $superfatSoftening,
            'superfat_lather_penalty' => $superfatLatherPenalty,
        ];
    }

    /**
     * @return array<string, float>
     */
    private function calculateWaterProcessModifiers(float $waterWeight, float $oilsWeight, float $activeLyeWeight): array
    {
        if ($oilsWeight <= 0) {
            return [
                'firmness' => 0.0,
                'cure_speed' => 0.0,
                'cured_hardness' => 0.0,
                'shrinkage_risk' => 0.0,
            ];
        }

        $waterRatio = $waterWeight / $oilsWeight;
        $modifier = max(-6.0, min(6.0, (0.38 - $waterRatio) * 30.0));
        $concentration = $activeLyeWeight > 0
            ? 100.0 * $activeLyeWeight / ($activeLyeWeight + max(0.0, $waterWeight))
            : 0.0;

        return [
            'firmness' => $this->roundValue($modifier),
            'cure_speed' => $this->roundValue($modifier * 1.15),
            'cured_hardness' => $this->roundValue($modifier * 0.5),
            'shrinkage_risk' => 100.0 / (1.0 + exp(($concentration - 28.0) / 3.0)),
        ];
    }

    private function smoothTransition(float $value, float $start, float $end): float
    {
        $position = max(0.0, min(1.0, ($value - $start) / ($end - $start)));

        return $position * $position * (3.0 - (2.0 * $position));
    }

    private function calculateDosRisk(float $pu, float $superfat, float $iodine): float
    {
        $baseRisk = (1.8 * min($pu, 10.0))
            + (4.5 * max(0.0, min($pu - 10.0, 5.0)))
            + (8.0 * max(0.0, $pu - 15.0));
        $superfatRisk = max(0.0, $superfat) * 0.8;
        $iodineRisk = max(0.0, $iodine - 55.0) * 0.04;

        return max(0.0, min(100.0, $baseRisk + $superfatRisk + $iodineRisk));
    }

    /**
     * @param  array<string, float>  $fattyAcidProfile
     */
    private function calculateIodineValue(array $fattyAcidProfile): float
    {
        $iodine = 0.0;

        foreach ($fattyAcidProfile as $key => $percentage) {
            $iodine += $percentage * ($this->iodineFactors()[$key] ?? 0.0);
        }

        return $this->roundValue($iodine);
    }

    /**
     * @return array<string, float>
     */
    private function iodineFactors(): array
    {
        return $this->iodineFactorsCache ??= FattyAcid::query()
            ->pluck('iodine_factor', 'key')
            ->map(fn (string $factor): float => (float) $factor)
            ->all();
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
