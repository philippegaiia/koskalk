<?php

namespace App\Services;

use App\Enums\LiquidSoapNeutralizer;
use App\Models\Ingredient;
use App\Support\NumberLocale;
use InvalidArgumentException;

/**
 * Runs a liquid soap formula's paste through its process: resolves the
 * neutralizer row, finds the final batch weight the final dilution aims for,
 * and turns additions entered as % of that final batch into weights.
 */
class LiquidSoapFormulaProcessor
{
    public function __construct(private readonly LiquidSoapProcessCalculator $liquidSoapProcessCalculator) {}

    /**
     * Calculator input for the formula's neutralizer row, or null without one.
     *
     * @param  array<string, mixed>|null  $row
     * @param  array<string, mixed>  $liquidProcess
     * @return array{type: LiquidSoapNeutralizer, weight: float|int|string, solution_percentage: float|int|string, koh_sap_value: float|int|string|null}|null
     */
    public function neutralizer(?array $row, array $liquidProcess): ?array
    {
        if (! is_array($row) || ! is_numeric($row['ingredient_id'] ?? null)) {
            return null;
        }

        $ingredient = Ingredient::query()->with('sapProfile')->find((int) $row['ingredient_id']);
        $type = $ingredient?->liquidSoapNeutralizer();

        if ($type === null) {
            throw new InvalidArgumentException('Choose a platform neutralizer such as citric acid, lactic acid or stearic acid.');
        }

        return [
            'type' => $type,
            'weight' => $row['weight'] ?? 0,
            'solution_percentage' => $type->formsSoap() ? 100 : ($liquidProcess['neutralizer_solution_percentage'] ?? 100),
            'koh_sap_value' => $type->formsSoap() ? $ingredient->sapProfile?->koh_sap_value : null,
        ];
    }

    /**
     * Weight of the finished batch at the final dilution target, or null when
     * the neutralizer exceeds the free KOH and the result is not modelled.
     *
     * @param  array<string, string>  $paste  Output of LiquidSoapProcessCalculator::pasteComposition().
     * @param  array<string, mixed>  $liquidProcess
     * @param  array<string, mixed>|null  $neutralizer  Output of neutralizer().
     */
    public function finalBatchWeight(array $paste, array $liquidProcess, ?array $neutralizer): ?string
    {
        $result = $this->process($paste, $liquidProcess, $neutralizer, '0');

        if ($result['status'] !== 'ok') {
            return null;
        }

        $finalPercentage = $this->liquidSoapProcessCalculator->normalizeSettings($liquidProcess)['final_dilution_percentage'];

        return bcdiv(bcmul($result['final_anhydrous_soap_weight'], '100', 18), $finalPercentage, 18);
    }

    /**
     * @param  array<string, string>  $paste
     * @param  array<string, mixed>  $liquidProcess
     * @param  array<string, mixed>|null  $neutralizer
     * @return array<string, mixed>
     */
    public function process(array $paste, array $liquidProcess, ?array $neutralizer, float|int|string $additionsWeight): array
    {
        $measuredPasteWeight = $liquidProcess['measured_paste_weight'] ?? null;

        return $this->liquidSoapProcessCalculator->calculate($paste, [
            'first_dilution_percentage' => $liquidProcess['first_dilution_percentage'] ?? LiquidSoapProcessCalculator::DEFAULT_FIRST_DILUTION_PERCENTAGE,
            'final_dilution_percentage' => $liquidProcess['final_dilution_percentage'] ?? null,
            'measured_paste_weight' => $measuredPasteWeight === '' ? null : $measuredPasteWeight,
            'additions_weight' => $additionsWeight,
            'neutralizer' => $neutralizer,
        ]);
    }

    /**
     * Additive and fragrance rows carrying the weights the process sized,
     * in the order the process read them, with their % of oils to match.
     *
     * @param  array<string, mixed>  $phaseItems
     * @param  array<string, mixed>  $liquidProcess  Process result with status ok.
     * @return array<string, mixed>
     */
    public function withAdditionWeights(array $phaseItems, array $liquidProcess, float|int|string $oilWeight): array
    {
        $weights = collect($liquidProcess['additions'] ?? [])->groupBy('phase_key')->map(fn ($additions) => $additions->pluck('weight')->values());
        $oilWeight = (float) $oilWeight;

        foreach (['additives', 'fragrance'] as $phaseKey) {
            if (! is_array($phaseItems[$phaseKey] ?? null)) {
                continue;
            }

            $phaseWeights = $weights->get($phaseKey, collect());
            $position = 0;

            foreach ($phaseItems[$phaseKey] as $index => $row) {
                if (! is_array($row) || ! is_numeric($row['ingredient_id'] ?? null)) {
                    continue;
                }

                $weight = $phaseWeights->get($position++);

                if ($weight === null) {
                    continue;
                }

                $phaseItems[$phaseKey][$index]['weight'] = (float) $weight;
                $phaseItems[$phaseKey][$index]['percentage'] = $oilWeight > 0 ? (float) $weight * 100 / $oilWeight : 0.0;
            }
        }

        return $phaseItems;
    }

    /**
     * Water added after the paste: both dilutions and the neutralizer
     * solution water, from a calculated process.
     *
     * @param  array<string, mixed>  $liquidProcess  Process result with status ok.
     */
    public function addedDilutionWaterWeight(array $liquidProcess): string
    {
        $neutralizer = $liquidProcess['neutralizer'] ?? null;
        $solutionWater = is_array($neutralizer)
            ? bcsub((string) $neutralizer['solution_weight'], (string) $neutralizer['weight'], 18)
            : '0';

        return bcadd(
            bcadd((string) data_get($liquidProcess, 'stages.first_dilution.add_weight', '0'), (string) data_get($liquidProcess, 'stages.final_dilution.add_weight', '0'), 18),
            bccomp($solutionWater, '0', 18) > 0 ? $solutionWater : '0',
            18,
        );
    }

    /**
     * An addition row's weight: its % of the final batch when the bench sent
     * one and the final batch is known, otherwise the given weight.
     *
     * @param  array<string, mixed>  $row
     */
    public function additionWeight(array $row, ?string $finalBatchWeight, float|int|string $fallbackWeight): string
    {
        $finalPercentage = NumberLocale::normalizeDecimalString(
            is_float($row['final_percentage'] ?? null) ? sprintf('%.12F', $row['final_percentage']) : ($row['final_percentage'] ?? null),
        );

        if ($finalPercentage === null || $finalBatchWeight === null) {
            return NumberLocale::normalizeDecimalString(is_float($fallbackWeight) ? sprintf('%.12F', $fallbackWeight) : $fallbackWeight) ?? '0';
        }

        return bcdiv(bcmul($finalPercentage, $finalBatchWeight, 18), '100', 18);
    }
}
