<?php

namespace App\Services;

use App\Enums\LiquidSoapNeutralizer;
use App\SoapSap;
use App\Support\NumberLocale;
use InvalidArgumentException;

/**
 * Paste-method liquid soap: the paste, a first dilution, an optional
 * neutralization of free KOH, and a final dilution to a target concentration.
 *
 * Every concentration is expressed on anhydrous soap: soap salts, the
 * glycerine they released and unsaponified lipids, which equals the lipids
 * plus the KOH that reacted. Free KOH, flake impurities, water, acids and
 * their salts are reported beside it, never inside it.
 *
 * Masses are computed with bcmath on decimal strings and returned rounded to
 * four decimals.
 */
class LiquidSoapProcessCalculator
{
    public const DEFAULT_FIRST_DILUTION_PERCENTAGE = '35';

    private const SCALE = 18;

    private const OUTPUT_SCALE = 4;

    private const NEUTRALIZER_TOLERANCE_GRAMS = '0.005';

    /** Most decimals PHP's sprintf() writes for a float. */
    private const MAX_FLOAT_DECIMALS = 53;

    public function __construct(private readonly MassConverter $massConverter) {}

    /**
     * Read the paste out of a soap calculation made without post-reaction oils.
     *
     * @param  array<string, mixed>  $soapCalculation  Output of SoapCalculationService::calculate().
     * @return array{
     *     mass_unit: string,
     *     lipids_weight: string,
     *     koh_weight: string,
     *     reacted_koh_weight: string,
     *     free_koh_weight: string,
     *     glycerine_weight: string,
     *     soap_salt_weight: string,
     *     unreacted_lipids_weight: string,
     *     koh_material_weight: string,
     *     koh_impurity_weight: string,
     *     alkali_liquid_weight: string,
     *     theoretical_paste_weight: string,
     *     anhydrous_soap_weight: string
     * }
     */
    public function pasteComposition(array $soapCalculation, string $massUnit = 'g'): array
    {
        if (data_get($soapCalculation, 'lye.selected.type') !== 'koh') {
            throw new InvalidArgumentException('Liquid soap paste requires KOH as the only alkali.');
        }

        $lipidsWeight = $this->decimal(data_get($soapCalculation, 'totals.oils_weight'), 'Oil weight');
        $kohWeight = $this->decimal(data_get($soapCalculation, 'lye.selected.koh_weight'), 'KOH weight');
        $freeKohWeight = $this->decimal(data_get($soapCalculation, 'lye.excess.koh_weight', 0), 'Free KOH weight');
        $glycerineWeight = $this->decimal(data_get($soapCalculation, 'lye.selected.glycerine_weight'), 'Glycerine weight');
        $saponifiedFraction = $this->decimal(data_get($soapCalculation, 'lye.saponified_fraction', 1), 'Saponified fraction');
        $kohMaterialWeight = $this->decimal(data_get($soapCalculation, 'lye.selected.koh_to_weigh'), 'KOH to weigh');
        $alkaliLiquidWeight = $this->decimal(data_get($soapCalculation, 'lye.water.weight'), 'Alkali solution liquid weight');

        $reactedKohWeight = bcsub($kohWeight, $freeKohWeight, self::SCALE);
        $saponifiedLipidsWeight = bcmul($lipidsWeight, $saponifiedFraction, self::SCALE);

        $composition = array_map($this->round(...), [
            'lipids_weight' => $lipidsWeight,
            'koh_weight' => $kohWeight,
            'reacted_koh_weight' => $reactedKohWeight,
            'free_koh_weight' => $freeKohWeight,
            'glycerine_weight' => $glycerineWeight,
            'soap_salt_weight' => bcsub(bcadd($saponifiedLipidsWeight, $reactedKohWeight, self::SCALE), $glycerineWeight, self::SCALE),
            'unreacted_lipids_weight' => bcsub($lipidsWeight, $saponifiedLipidsWeight, self::SCALE),
            'koh_material_weight' => $kohMaterialWeight,
            'koh_impurity_weight' => bcsub($kohMaterialWeight, $kohWeight, self::SCALE),
            'alkali_liquid_weight' => $alkaliLiquidWeight,
            'theoretical_paste_weight' => $this->sum($lipidsWeight, $kohMaterialWeight, $alkaliLiquidWeight),
            'anhydrous_soap_weight' => bcadd($lipidsWeight, $reactedKohWeight, self::SCALE),
        ]);

        return ['mass_unit' => $massUnit, ...$composition];
    }

    /**
     * Theoretical acid weight that neutralizes the given free KOH.
     */
    public function recommendedNeutralizerWeight(
        float|int|string $freeKohWeight,
        LiquidSoapNeutralizer $neutralizer,
        float|int|string|null $kohSapValue = null,
    ): string {
        return $this->round($this->recommendedWeight(
            $this->max('0', $this->decimal($freeKohWeight, 'Free KOH weight')),
            $neutralizer,
            $kohSapValue,
        ));
    }

    /**
     * Process settings saved with a liquid soap formula, as canonical decimal
     * strings. Batch measurements such as the weighed paste are not part of
     * the formula and are passed to calculate() instead.
     *
     * @param  array<string, mixed>  $settings
     * @return array{first_dilution_percentage: string, final_dilution_percentage: string, neutralizer_solution_percentage: string}
     */
    public function normalizeSettings(array $settings): array
    {
        [$firstPercentage, $finalPercentage] = $this->dilutionTargets($settings);

        return [
            'first_dilution_percentage' => $this->canonical($firstPercentage),
            'final_dilution_percentage' => $this->canonical($finalPercentage),
            'neutralizer_solution_percentage' => $this->canonical($this->percentage(
                $settings['neutralizer_solution_percentage'] ?? '100',
                'Neutralizer solution',
                allowHundred: true,
            )),
        ];
    }

    /**
     * @param  array<string, float|int|string>  $paste  Output of pasteComposition().
     * @param  array{
     *     final_dilution_percentage: float|int|string,
     *     first_dilution_percentage?: float|int|string,
     *     measured_paste_weight?: float|int|string|null,
     *     additions_weight?: float|int|string,
     *     neutralizer?: array{
     *         type: LiquidSoapNeutralizer|string,
     *         weight: float|int|string,
     *         solution_percentage?: float|int|string,
     *         koh_sap_value?: float|int|string|null
     *     }|null
     * }  $process
     * @return array<string, mixed>
     */
    public function calculate(array $paste, array $process): array
    {
        [$firstPercentage, $finalPercentage] = $this->dilutionTargets($process);

        $additionsWeight = $this->nonNegative($process['additions_weight'] ?? 0, 'Additions weight');

        $lipidsWeight = $this->nonNegative($paste['lipids_weight'] ?? null, 'Oil weight');
        $kohWeight = $this->nonNegative($paste['koh_weight'] ?? null, 'KOH weight');
        $kohMaterialWeight = $this->nonNegative($paste['koh_material_weight'] ?? null, 'KOH to weigh');
        $alkaliLiquidWeight = $this->nonNegative($paste['alkali_liquid_weight'] ?? null, 'Alkali solution liquid weight');
        $freeKohWeight = $this->nonNegative($paste['free_koh_weight'] ?? null, 'Free KOH weight');
        $anhydrousSoapWeight = $this->nonNegative($paste['anhydrous_soap_weight'] ?? null, 'Anhydrous soap weight');
        $theoreticalPasteWeight = $this->sum($lipidsWeight, $kohMaterialWeight, $alkaliLiquidWeight);
        $pasteWeight = $this->pasteWeight(
            $process['measured_paste_weight'] ?? null,
            $theoreticalPasteWeight,
            bcadd($lipidsWeight, $kohMaterialWeight, self::SCALE),
        );

        $firstTarget = $this->massAtPercentage($anhydrousSoapWeight, $firstPercentage);
        $firstAdd = $this->max('0', bcsub($firstTarget, $pasteWeight, self::SCALE));
        $firstMass = bcadd($pasteWeight, $firstAdd, self::SCALE);

        $neutralizer = $process['neutralizer'] ?? null;
        $tolerance = $this->neutralizerTolerance($neutralizer, $paste['mass_unit'] ?? 'g');
        $neutralization = $this->neutralization($freeKohWeight, $neutralizer);
        $neutralizedMass = bcadd($firstMass, $neutralization['solution_weight'], self::SCALE);
        $finalAnhydrousSoapWeight = bcadd($anhydrousSoapWeight, $neutralization['anhydrous_soap_gain'], self::SCALE);
        $isSupported = bccomp($neutralization['excess_acid_weight'], $tolerance, self::SCALE) <= 0;

        $stages = [
            'first_dilution' => [
                'target_percentage' => $this->round($firstPercentage),
                'add_weight' => $this->round($firstAdd),
                'mass' => $this->round($firstMass),
                'anhydrous_percentage' => $this->percentageOf($anhydrousSoapWeight, $firstMass),
                'status' => bccomp($firstTarget, $pasteWeight, self::SCALE) < 0 ? 'already_below_target' : 'ok',
            ],
            'neutralization' => [
                'add_weight' => $this->round($neutralization['solution_weight']),
                'mass' => $this->round($neutralizedMass),
                'anhydrous_percentage' => $isSupported ? $this->percentageOf($finalAnhydrousSoapWeight, $neutralizedMass) : null,
            ],
        ];

        $result = [
            'status' => $isSupported ? 'ok' : 'acid_exceeds_free_koh',
            'anhydrous_soap_weight' => $this->round($anhydrousSoapWeight),
            'paste' => [
                'theoretical_weight' => $this->round($theoreticalPasteWeight),
                'weight' => $this->round($pasteWeight),
                'evaporated_weight' => $this->round(bcsub($theoreticalPasteWeight, $pasteWeight, self::SCALE)),
                'anhydrous_percentage' => $this->percentageOf($anhydrousSoapWeight, $pasteWeight),
            ],
            'neutralizer' => $neutralization['summary'],
            'recommended_neutralizer_weights' => collect([
                LiquidSoapNeutralizer::CitricAcidAnhydrous,
                LiquidSoapNeutralizer::CitricAcidMonohydrate,
                LiquidSoapNeutralizer::LacticAcid,
            ])->mapWithKeys(fn (LiquidSoapNeutralizer $neutralizer): array => [
                $neutralizer->value => $this->round($this->recommendedWeight($freeKohWeight, $neutralizer)),
            ])->all(),
            'warnings' => $this->warnings($freeKohWeight, $neutralization, $tolerance, $neutralizer),
        ];

        if (! $isSupported) {
            return [
                ...$result,
                'final_anhydrous_soap_weight' => null,
                'final_weight' => null,
                'final_anhydrous_percentage' => null,
                'stages' => [
                    ...$stages,
                    'final_dilution' => [
                        'target_percentage' => $this->round($finalPercentage),
                        'additions_weight' => $this->round($additionsWeight),
                        'add_weight' => null,
                        'mass' => null,
                        'anhydrous_percentage' => null,
                        'status' => 'unsupported',
                    ],
                ],
                'composition' => null,
                'composition_percentages' => null,
            ];
        }

        $beforeFinalMass = bcadd($neutralizedMass, $additionsWeight, self::SCALE);
        $finalTarget = $this->massAtPercentage($finalAnhydrousSoapWeight, $finalPercentage);
        $finalAdd = $this->max('0', bcsub($finalTarget, $beforeFinalMass, self::SCALE));
        $finalWeight = bcadd($beforeFinalMass, $finalAdd, self::SCALE);

        $composition = [
            'soap_salt_weight' => bcadd($this->nonNegative($paste['soap_salt_weight'] ?? null, 'Soap salt weight'), $neutralization['soap_salt_gain'], self::SCALE),
            'glycerine_weight' => $this->nonNegative($paste['glycerine_weight'] ?? null, 'Glycerine weight'),
            'unreacted_lipids_weight' => bcadd($this->nonNegative($paste['unreacted_lipids_weight'] ?? null, 'Unreacted lipids weight'), $neutralization['excess_fatty_acid_weight'], self::SCALE),
            'free_koh_weight' => $neutralization['residual_koh_weight'],
            'koh_impurity_weight' => bcsub($kohMaterialWeight, $kohWeight, self::SCALE),
            'neutralization_salt_weight' => $neutralization['salt_weight'],
            'excess_acid_weight' => $neutralization['excess_acid_weight'],
            'additions_weight' => $additionsWeight,
            'water_weight' => $this->sum(
                $alkaliLiquidWeight,
                bcsub($pasteWeight, $theoreticalPasteWeight, self::SCALE),
                $firstAdd,
                $neutralization['solution_water_weight'],
                $neutralization['reaction_water_weight'],
                $finalAdd,
            ),
        ];

        return [
            ...$result,
            'final_anhydrous_soap_weight' => $this->round($finalAnhydrousSoapWeight),
            'final_weight' => $this->round($finalWeight),
            'final_anhydrous_percentage' => $this->percentageOf($finalAnhydrousSoapWeight, $finalWeight),
            'stages' => [
                ...$stages,
                'final_dilution' => [
                    'target_percentage' => $this->round($finalPercentage),
                    'additions_weight' => $this->round($additionsWeight),
                    'add_weight' => $this->round($finalAdd),
                    'mass' => $this->round($finalWeight),
                    'anhydrous_percentage' => $this->percentageOf($finalAnhydrousSoapWeight, $finalWeight),
                    'status' => bccomp($finalTarget, $beforeFinalMass, self::SCALE) < 0 ? 'target_exceeded' : 'ok',
                ],
            ],
            'composition' => array_map($this->round(...), $composition),
            'composition_percentages' => array_map(
                fn (string $weight): string => $this->percentageOf($weight, $finalWeight),
                $composition,
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array{0: string, 1: string}
     */
    private function dilutionTargets(array $settings): array
    {
        $firstPercentage = $this->percentage($settings['first_dilution_percentage'] ?? self::DEFAULT_FIRST_DILUTION_PERCENTAGE, 'First dilution');
        $finalPercentage = $this->percentage($settings['final_dilution_percentage'] ?? null, 'Final dilution');

        if (bccomp($finalPercentage, $firstPercentage, self::SCALE) > 0) {
            throw new InvalidArgumentException('The final dilution must not be more concentrated than the first dilution.');
        }

        return [$firstPercentage, $finalPercentage];
    }

    private function canonical(string $decimal): string
    {
        return str_contains($decimal, '.') ? rtrim(rtrim($decimal, '0'), '.') : $decimal;
    }

    /**
     * A weighed paste cannot have lost more than all the liquid it received.
     */
    private function pasteWeight(mixed $measuredPasteWeight, string $theoreticalPasteWeight, string $minimumPasteWeight): string
    {
        if ($measuredPasteWeight === null) {
            return $theoreticalPasteWeight;
        }

        $weight = $this->decimal($measuredPasteWeight, 'Measured paste weight');

        if (bccomp($weight, $minimumPasteWeight, self::SCALE) < 0) {
            throw new InvalidArgumentException(sprintf(
                'The measured paste weight cannot be below the oils and KOH weighed in (%s g).',
                $this->round($minimumPasteWeight),
            ));
        }

        return $weight;
    }

    /**
     * @param  array<string, mixed>|null  $neutralizer
     * @return array{
     *     solution_weight: string,
     *     solution_water_weight: string,
     *     reaction_water_weight: string,
     *     anhydrous_soap_gain: string,
     *     soap_salt_gain: string,
     *     excess_fatty_acid_weight: string,
     *     residual_koh_weight: string,
     *     salt_weight: string,
     *     excess_acid_weight: string,
     *     summary: array<string, string>|null
     * }
     */
    private function neutralization(string $freeKohWeight, ?array $neutralizer): array
    {
        if ($neutralizer === null) {
            return [
                'solution_weight' => '0',
                'solution_water_weight' => '0',
                'reaction_water_weight' => '0',
                'anhydrous_soap_gain' => '0',
                'soap_salt_gain' => '0',
                'excess_fatty_acid_weight' => '0',
                'residual_koh_weight' => $freeKohWeight,
                'salt_weight' => '0',
                'excess_acid_weight' => '0',
                'summary' => null,
            ];
        }

        $type = $neutralizer['type'] instanceof LiquidSoapNeutralizer
            ? $neutralizer['type']
            : LiquidSoapNeutralizer::tryFrom((string) ($neutralizer['type'] ?? ''));

        if ($type === null) {
            throw new InvalidArgumentException('Unsupported neutralizer.');
        }

        $kohSapValue = $neutralizer['koh_sap_value'] ?? null;
        $capacity = $this->kohCapacity($type, $kohSapValue);
        $acidWeight = $this->nonNegative($neutralizer['weight'] ?? 0, 'Neutralizer weight');
        $solutionPercentage = $this->percentage($neutralizer['solution_percentage'] ?? '100', 'Neutralizer solution', allowHundred: true);
        $solutionWeight = $this->massAtPercentage($acidWeight, $solutionPercentage);

        $neutralizedKohWeight = $this->min($freeKohWeight, bcmul($acidWeight, $capacity, self::SCALE));
        $reactedAcidWeight = bcdiv($neutralizedKohWeight, $capacity, self::SCALE);
        $excessAcidWeight = $this->max('0', bcsub($acidWeight, $reactedAcidWeight, self::SCALE));
        $hydrateWaterWeight = bcmul($reactedAcidWeight, $type->hydrateWaterFraction(), self::SCALE);
        $formedWaterWeight = bcdiv(
            bcmul($neutralizedKohWeight, LiquidSoapNeutralizer::WATER_MOLAR_MASS, self::SCALE),
            LiquidSoapNeutralizer::KOH_MOLAR_MASS,
            self::SCALE,
        );
        $reactionWaterWeight = bcadd($formedWaterWeight, $hydrateWaterWeight, self::SCALE);
        $productWeight = bcsub(bcadd($reactedAcidWeight, $neutralizedKohWeight, self::SCALE), $reactionWaterWeight, self::SCALE);
        $formsSoap = $type->formsSoap();

        return [
            'solution_weight' => $solutionWeight,
            'solution_water_weight' => bcsub($solutionWeight, $acidWeight, self::SCALE),
            'reaction_water_weight' => $reactionWaterWeight,
            'anhydrous_soap_gain' => $formsSoap ? bcadd($productWeight, $excessAcidWeight, self::SCALE) : '0',
            'soap_salt_gain' => $formsSoap ? $productWeight : '0',
            'excess_fatty_acid_weight' => $formsSoap ? $excessAcidWeight : '0',
            'residual_koh_weight' => bcsub($freeKohWeight, $neutralizedKohWeight, self::SCALE),
            'salt_weight' => $formsSoap ? '0' : $productWeight,
            'excess_acid_weight' => $formsSoap ? '0' : $excessAcidWeight,
            'summary' => [
                'type' => $type->value,
                'recommended_weight' => $this->round($this->recommendedWeight($freeKohWeight, $type, $kohSapValue)),
                'weight' => $this->round($acidWeight),
                'solution_percentage' => $this->round($solutionPercentage),
                'solution_weight' => $this->round($solutionWeight),
                'neutralized_koh_weight' => $this->round($neutralizedKohWeight),
                'excess_acid_weight' => $this->round($excessAcidWeight),
                'product_weight' => $this->round($productWeight),
                'excess_fatty_acid_weight' => $this->round($formsSoap ? $excessAcidWeight : '0'),
            ],
        ];
    }

    /**
     * Acid that exactly neutralizes the given free KOH, unrounded.
     */
    public function recommendedWeight(string $freeKohWeight, LiquidSoapNeutralizer $neutralizer, float|int|string|null $kohSapValue = null): string
    {
        return bcdiv($freeKohWeight, $this->kohCapacity($neutralizer, $kohSapValue), self::SCALE);
    }

    private function kohCapacity(LiquidSoapNeutralizer $neutralizer, float|int|string|null $kohSapValue): string
    {
        if (! $neutralizer->formsSoap()) {
            return (string) $neutralizer->kohCapacity();
        }

        $sapValue = $kohSapValue === null ? null : $this->decimal($kohSapValue, 'KOH SAP value');

        if ($sapValue === null || bccomp($sapValue, '0', self::SCALE) <= 0) {
            throw new InvalidArgumentException('A fatty acid neutralizer needs its KOH SAP value.');
        }

        return bccomp($sapValue, '1', self::SCALE) > 0
            ? bcdiv($sapValue, (string) SoapSap::PROFESSIONAL_KOH_SAP_DIVISOR, self::SCALE)
            : $sapValue;
    }

    /**
     * @param  array<string, mixed>  $neutralization
     * @param  array<string, mixed>|null  $neutralizer
     * @return array<int, string>
     */
    private function warnings(string $freeKohWeight, array $neutralization, string $tolerance, ?array $neutralizer): array
    {
        $warnings = [];

        if ($this->isPositive($freeKohWeight)) {
            $warnings[] = 'neutralizer_amount_is_theoretical';
        }

        $residualAcidEquivalent = $neutralizer === null ? null : $this->recommendedWeight(
            $neutralization['residual_koh_weight'],
            $neutralizer['type'] instanceof LiquidSoapNeutralizer ? $neutralizer['type'] : LiquidSoapNeutralizer::from($neutralizer['type']),
            $neutralizer['koh_sap_value'] ?? null,
        );

        if ($this->isPositive($neutralization['residual_koh_weight']) && ($residualAcidEquivalent === null || bccomp($residualAcidEquivalent, $tolerance, self::SCALE) > 0)) {
            $warnings[] = 'free_koh_remains';
        }

        if (bccomp($neutralization['excess_acid_weight'], $tolerance, self::SCALE) > 0) {
            $warnings[] = 'excess_acid_splits_soap';
        }

        if (bccomp($neutralization['excess_fatty_acid_weight'], $tolerance, self::SCALE) > 0) {
            $warnings[] = 'excess_fatty_acid_raises_superfat';
        }

        return $warnings;
    }

    /**
     * The estimate accepts five milligrams or the rounding of a dose and its
     * free-KOH input, whichever is larger, expressed in the batch's unit.
     * Trace excess stays in the mass balance as unreacted acid.
     *
     * @param  array<string, mixed>|null  $neutralizer
     */
    private function neutralizerTolerance(?array $neutralizer, string $massUnit): string
    {
        if ($neutralizer === null) {
            return '0';
        }

        $type = $neutralizer['type'] instanceof LiquidSoapNeutralizer
            ? $neutralizer['type']
            : LiquidSoapNeutralizer::tryFrom((string) ($neutralizer['type'] ?? ''));

        if ($type === null) {
            throw new InvalidArgumentException('Unsupported neutralizer.');
        }

        $rounding = bcadd('0.00005', $this->recommendedWeight('0.00005', $type, $neutralizer['koh_sap_value'] ?? null), self::SCALE);

        return $this->max($this->massConverter->fromGrams(self::NEUTRALIZER_TOLERANCE_GRAMS, $massUnit), $rounding);
    }

    private function percentage(mixed $value, string $label, bool $allowHundred = false): string
    {
        $upperBound = $allowHundred ? 'at most 100' : 'below 100';
        $message = "{$label} percentage must be greater than 0 and {$upperBound}.";

        if ($value === null) {
            throw new InvalidArgumentException($message);
        }

        $percentage = $this->decimal($value, "{$label} percentage");
        $upperComparison = bccomp($percentage, '100', self::SCALE);

        if (bccomp($percentage, '0', self::SCALE) <= 0 || $upperComparison > 0 || (! $allowHundred && $upperComparison === 0)) {
            throw new InvalidArgumentException($message);
        }

        return $percentage;
    }

    private function nonNegative(mixed $value, string $label): string
    {
        $decimal = $this->decimal($value, $label);

        if (bccomp($decimal, '0', self::SCALE) < 0) {
            throw new InvalidArgumentException("{$label} must be zero or more.");
        }

        return $decimal;
    }

    /**
     * Finite decimal string from user or engine input. Floats become the
     * shortest decimal that reads back as the same float, so 35.1 stays
     * "35.1" rather than carrying binary noise, tiny values never collapse
     * to zero, and the result does not depend on the process locale.
     */
    private function decimal(mixed $value, string $label): string
    {
        if (is_float($value)) {
            $value = is_finite($value) ? $this->floatToDecimal($value) : null;
        }

        $decimal = is_int($value) || is_string($value) ? NumberLocale::normalizeDecimalString($value) : null;

        if ($decimal === null) {
            throw new InvalidArgumentException("{$label} must be a finite number.");
        }

        return $decimal;
    }

    /**
     * Uses %F, which ignores the process locale, unlike %G or %f that would
     * write "35,1" under a decimal-comma LC_NUMERIC.
     */
    private function floatToDecimal(float $value): string
    {
        for ($decimals = 0; $decimals < self::MAX_FLOAT_DECIMALS; $decimals++) {
            $decimal = sprintf("%.{$decimals}F", $value);

            if ((float) $decimal === $value) {
                return $decimal;
            }
        }

        return sprintf('%.'.self::MAX_FLOAT_DECIMALS.'F', $value);
    }

    private function massAtPercentage(string $weight, string $percentage): string
    {
        return bcdiv(bcmul($weight, '100', self::SCALE), $percentage, self::SCALE);
    }

    private function percentageOf(string $part, string $whole): string
    {
        return bccomp($whole, '0', self::SCALE) > 0
            ? $this->round(bcdiv(bcmul($part, '100', self::SCALE), $whole, self::SCALE))
            : $this->round('0');
    }

    private function sum(string ...$values): string
    {
        return array_reduce($values, fn (string $total, string $value): string => bcadd($total, $value, self::SCALE), '0');
    }

    private function min(string $left, string $right): string
    {
        return bccomp($left, $right, self::SCALE) <= 0 ? $left : $right;
    }

    private function max(string $left, string $right): string
    {
        return bccomp($left, $right, self::SCALE) >= 0 ? $left : $right;
    }

    private function isPositive(string $value): bool
    {
        return bccomp($this->round($value), '0', self::OUTPUT_SCALE) > 0;
    }

    /**
     * Round half away from zero to four decimals.
     */
    private function round(string $value): string
    {
        $half = '0.'.str_repeat('0', self::OUTPUT_SCALE).'5';
        $rounded = bccomp($value, '0', self::SCALE) < 0
            ? bcsub($value, $half, self::OUTPUT_SCALE)
            : bcadd($value, $half, self::OUTPUT_SCALE);

        return bccomp($rounded, '0', self::OUTPUT_SCALE) === 0 ? bcadd('0', '0', self::OUTPUT_SCALE) : $rounded;
    }
}
