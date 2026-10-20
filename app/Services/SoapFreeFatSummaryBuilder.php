<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Support\NumberLocale;

class SoapFreeFatSummaryBuilder
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  list<array{phase_key: string, weight: float, ingredient: Ingredient|null}>  $contexts
     * @param  array<string, mixed>|null  $calculation
     * @param  array<string, mixed>  $basis
     * @return array{nominal_percentage: float, percentage: float|null, weight: float|null, basis: string, basis_weight: float, residual_water_percentage: float|null, unavailable_reason: string|null}|null
     */
    public function build(array $payload, array $contexts, ?array $calculation, array $basis): ?array
    {
        if ($calculation === null || ($payload['manufacturing_mode'] ?? 'saponify_in_formula') !== 'saponify_in_formula') {
            return null;
        }

        $materials = collect($contexts)->filter(fn (array $context): bool => $context['ingredient'] instanceof Ingredient
            && $context['ingredient']->canDriveSoapSaponification());
        $coreWeight = $materials->where('phase_key', 'saponified_oils')
            ->reduce(fn (string $sum, array $context): string => bcadd($sum, $this->decimal($context['weight']), 18), '0');

        if (bccomp($coreWeight, '0', 18) <= 0) {
            return null;
        }

        $additions = $materials->whereIn('phase_key', RecipeWorkbenchPhaseBlueprints::ADDITION_PHASES);
        $addedWeight = $additions->reduce(fn (string $sum, array $context): string => bcadd($sum, $this->decimal($context['weight']), 18), '0');
        $nominalPercentage = bcadd(
            $this->decimal(data_get($calculation, 'lye.superfat_percentage', 0)),
            bcdiv(bcmul($addedWeight, '100', 18), $coreWeight, 18),
            18,
        );
        $isLiquid = array_key_exists('liquid_process', $calculation);
        $unavailableReason = null;

        if ($isLiquid) {
            $process = $calculation['liquid_process'];

            if (! in_array($process['status'] ?? null, ['ok', 'acid_exceeds_free_koh'], true)) {
                return null;
            }

            if (in_array('free_koh_remains', $process['warnings'] ?? [], true)) {
                $unavailableReason = 'free_koh_remains';
            }

            $basisWeight = $this->decimal($process['final_weight'] ?? 0);
            $freeWeight = bcadd(
                $this->decimal(data_get($process, 'composition.unreacted_lipids_weight', 0)),
                $this->decimal(data_get($process, 'composition.free_fatty_acid_weight', 0)),
                18,
            );
            $freeWeight = bcadd($freeWeight, $addedWeight, 18);
        } else {
            $basisWeight = $this->decimal($basis['cured_weight'] ?? 0);
            $freeWeight = bcmul($coreWeight, $this->freeShare(data_get($calculation, 'lye.saponified_fraction', 1)), 18);
            $freeWeight = $additions->reduce(function (string $sum, array $context) use ($calculation): string {
                $freeShare = $this->freeShare(data_get($calculation, 'lye.post_reaction_saponified_fraction', 0));

                return bcadd($sum, bcmul($this->decimal($context['weight']), $freeShare, 18), 18);
            }, $freeWeight);
        }

        if (bccomp($basisWeight, '0', 18) <= 0) {
            return null;
        }

        return [
            'nominal_percentage' => (float) $nominalPercentage,
            'percentage' => $unavailableReason === null ? (float) bcdiv(bcmul($freeWeight, '100', 18), $basisWeight, 18) : null,
            'weight' => $unavailableReason === null ? (float) $freeWeight : null,
            'basis' => $isLiquid ? 'finished_liquid_soap' : 'cured_soap',
            'basis_weight' => (float) $basisWeight,
            'residual_water_percentage' => $isLiquid ? null : (float) ($basis['residual_water_percentage'] ?? 0),
            'unavailable_reason' => $unavailableReason,
        ];
    }

    private function freeShare(mixed $reactedShare): string
    {
        return bcsub('1', $this->decimal(max(0.0, min(1.0, (float) $reactedShare))), 18);
    }

    private function decimal(mixed $value): string
    {
        return NumberLocale::normalizeDecimalString(is_float($value) ? sprintf('%.18F', $value) : $value) ?? '0';
    }
}
