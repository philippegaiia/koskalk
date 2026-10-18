<?php

namespace App\Services;

use App\Enums\LiquidSoapNeutralizer;
use App\Models\Ingredient;
use App\Support\NumberLocale;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Runs a liquid soap formula's paste through its process: resolves the
 * neutralizer row, finds the final batch weight the final dilution aims for,
 * and turns additions entered as % of that final batch into weights.
 */
class LiquidSoapFormulaProcessor
{
    public function __construct(
        private readonly LiquidSoapProcessCalculator $liquidSoapProcessCalculator,
        private readonly LyeLiquidAllocationService $lyeLiquidAllocationService,
        private readonly LyeLiquidIngredientValidator $lyeLiquidIngredientValidator,
    ) {}

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
     * Each dilution step's added mass split into its liquids (rows as % of
     * that step's water) and the plain water left. A step that adds nothing
     * gives its liquids 0 g.
     *
     * @param  array<string, mixed>  $process  Process result; an uncalculated step keeps its shares with zero derived weights.
     * @param  array<string, mixed>  $phaseItems  Rows keyed by phase.
     * @return array<string, array{water_weight: float, liquids: list<array<string, mixed>>}>
     */
    public function dilutionLiquids(array $process, array $phaseItems): array
    {
        return collect(RecipeWorkbenchPhaseBlueprints::DILUTION_PHASES)
            ->mapWithKeys(function (string $phaseKey) use ($process, $phaseItems): array {
                $field = "phase_items.{$phaseKey}";
                $rows = $this->lyeLiquidIngredientValidator->normalizeRows(
                    collect($phaseItems[$phaseKey] ?? [])->values()->all(),
                    $field,
                );

                try {
                    $allocation = $this->lyeLiquidAllocationService->allocateFresh(
                        (string) (data_get($process, "stages.{$phaseKey}.add_weight") ?? '0'),
                        $rows,
                    );
                } catch (InvalidArgumentException $exception) {
                    throw ValidationException::withMessages([$field => $exception->getMessage()]);
                }

                return [$phaseKey => [
                    'water_weight' => $allocation['water_weight'],
                    'liquids' => collect($allocation['substitutions'])
                        ->map(fn (array $row): array => [
                            ...collect($row)->only(['id', 'ingredient_id'])->all(),
                            'percentage' => (float) $row['percentage'],
                            'weight' => (float) $row['weight'],
                        ])
                        ->values()
                        ->all(),
                ]];
            })
            ->all();
    }

    /**
     * Dilution liquid rows carrying the weight their step gave them; rows the
     * process left out, or whose step adds nothing, weigh 0. With an oil
     * weight, their percentage becomes a % of oils so every consumer that
     * reads soap rows that way sees the same amount; without one, it stays
     * the % of the step water.
     *
     * @param  array<string, mixed>  $phaseItems
     * @param  array<string, mixed>  $liquidProcess  Process result with status ok.
     * @return array<string, mixed>
     */
    public function withDilutionLiquidWeights(array $phaseItems, array $liquidProcess, float|int|string|null $oilWeight = null): array
    {
        $oilWeight = $oilWeight === null ? null : (float) $oilWeight;

        foreach (RecipeWorkbenchPhaseBlueprints::DILUTION_PHASES as $phaseKey) {
            if (! is_array($phaseItems[$phaseKey] ?? null)) {
                continue;
            }

            $weights = collect(data_get($liquidProcess, "dilution_liquids.{$phaseKey}.liquids", []))->pluck('weight')->values();
            $position = 0;

            foreach ($phaseItems[$phaseKey] as $index => $row) {
                if (! is_array($row) || ! is_numeric($row['ingredient_id'] ?? null)) {
                    continue;
                }

                // The same rows the allocation keeps: a share above zero.
                $weight = (float) ($row['percentage'] ?? 0) > 0 ? (float) ($weights->get($position++) ?? 0) : 0.0;
                $phaseItems[$phaseKey][$index]['weight'] = $weight;

                if ($oilWeight !== null) {
                    $phaseItems[$phaseKey][$index]['percentage'] = $oilWeight > 0 ? $weight * 100 / $oilWeight : 0.0;
                }
            }
        }

        return $phaseItems;
    }

    /**
     * Plain water added after the paste: both dilutions minus their liquids,
     * and the neutralizer solution water.
     *
     * @param  array<string, mixed>  $liquidProcess  Process result with status ok.
     */
    public function addedDilutionWaterWeight(array $liquidProcess): string
    {
        $neutralizer = $liquidProcess['neutralizer'] ?? null;
        $solutionWater = is_array($neutralizer)
            ? bcsub((string) $neutralizer['solution_weight'], (string) $neutralizer['weight'], 18)
            : '0';
        $dilutionWater = collect(RecipeWorkbenchPhaseBlueprints::DILUTION_PHASES)
            ->reduce(function (string $total, string $phaseKey) use ($liquidProcess): string {
                $added = (string) data_get($liquidProcess, "stages.{$phaseKey}.add_weight", '0');
                $liquids = collect(data_get($liquidProcess, "dilution_liquids.{$phaseKey}.liquids", []))
                    ->reduce(fn (string $sum, array $liquid): string => bcadd($sum, (string) $liquid['weight'], 18), '0');

                return bcadd($total, bcsub($added === '' ? '0' : $added, $liquids, 18), 18);
            }, '0');

        return bcadd($dilutionWater, bccomp($solutionWater, '0', 18) > 0 ? $solutionWater : '0', 18);
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
