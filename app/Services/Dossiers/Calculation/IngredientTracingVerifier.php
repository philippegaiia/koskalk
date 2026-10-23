<?php

namespace App\Services\Dossiers\Calculation;

use App\Support\DecimalMath;
use InvalidArgumentException;

/**
 * Ingredient tracing before normalisation (spec §4.1): a total of 100 % does not prove the allocation is right.
 * Any difference beyond the representation tolerance is a gap that blocks submission.
 */
class IngredientTracingVerifier
{
    /**
     * @param  list<array{key: string, grams: string}>  $initial
     * @param  list<array{from: string, to: string, grams: string}>  $contributions
     * @param  list<array{from: string, kind: string, grams: string}>  $losses
     * @param  list<array{key: string, grams: string, percent?: string}>  $final
     * @return array{passes: bool, tolerance_g: string, gaps: list<array{type: string, key: string, difference: string}>}
     */
    public function verify(array $initial, array $contributions, array $losses, array $final, ?string $toleranceGrams = null): array
    {
        $tolerance = DecimalMath::of($toleranceGrams ?? config('dossiers.mass_balance_tolerance_g'));

        if (DecimalMath::cmp($tolerance, '0') < 0 || DecimalMath::cmp($tolerance, DecimalMath::of(config('dossiers.mass_balance_tolerance_max_g'))) > 0) {
            throw new InvalidArgumentException('The tracing tolerance must stay within the representation bound.');
        }

        $percentTolerance = DecimalMath::of(config('dossiers.percent_tolerance'));
        $initialGrams = collect($initial)->mapWithKeys(fn (array $row): array => [$row['key'] => DecimalMath::of($row['grams'])]);
        $finalGrams = collect($final)->mapWithKeys(fn (array $row): array => [$row['key'] => DecimalMath::of($row['grams'])]);
        $gaps = [];
        $allocated = [];
        $received = [];

        foreach ($contributions as $contribution) {
            $grams = DecimalMath::of($contribution['grams']);
            $gaps = [...$gaps, ...$this->amountGaps($contribution['from'], $grams)];

            if (! $initialGrams->has($contribution['from'])) {
                $gaps[] = ['type' => 'unknown_initial', 'key' => $contribution['from'], 'difference' => $grams];
            }

            if (! $finalGrams->has($contribution['to'])) {
                $gaps[] = ['type' => 'unknown_final', 'key' => $contribution['to'], 'difference' => $grams];
            }

            $allocated[$contribution['from']] = DecimalMath::add($allocated[$contribution['from']] ?? '0', $grams);
            $received[$contribution['to']] = DecimalMath::add($received[$contribution['to']] ?? '0', $grams);
        }

        foreach ($losses as $loss) {
            $grams = DecimalMath::of($loss['grams']);
            $gaps = [...$gaps, ...$this->amountGaps($loss['from'], $grams)];

            if (! $initialGrams->has($loss['from'])) {
                $gaps[] = ['type' => 'unknown_initial', 'key' => $loss['from'], 'difference' => $grams];
            }

            $allocated[$loss['from']] = DecimalMath::add($allocated[$loss['from']] ?? '0', $grams);
        }

        foreach ($initialGrams as $key => $grams) {
            $difference = DecimalMath::sub($grams, $allocated[$key] ?? '0');

            if (! DecimalMath::withinTolerance($difference, '0', $tolerance)) {
                $gaps[] = ['type' => 'unallocated_initial', 'key' => $key, 'difference' => $difference];
            }
        }

        foreach ($finalGrams as $key => $grams) {
            $difference = DecimalMath::sub($grams, $received[$key] ?? '0');

            if (! DecimalMath::withinTolerance($difference, '0', $tolerance)) {
                $gaps[] = ['type' => 'unexplained_final', 'key' => $key, 'difference' => $difference];
            }
        }

        $totalFinal = DecimalMath::add(...$finalGrams->values()->all());
        $totalLosses = DecimalMath::add(...collect($losses)->map(fn (array $loss): string => DecimalMath::of($loss['grams']))->all());
        $balance = DecimalMath::sub(DecimalMath::sub(DecimalMath::add(...$initialGrams->values()->all()), $totalLosses), $totalFinal);

        if (! DecimalMath::withinTolerance($balance, '0', $tolerance)) {
            $gaps[] = ['type' => 'mass_balance', 'key' => 'total', 'difference' => $balance];
        }

        $gaps = [...$gaps, ...$this->percentGaps($final, $totalFinal, $percentTolerance)];

        return ['passes' => $gaps === [], 'tolerance_g' => $tolerance, 'gaps' => $gaps];
    }

    /**
     * @return list<array{type: string, key: string, difference: string}>
     */
    private function amountGaps(string $key, string $grams): array
    {
        return DecimalMath::cmp($grams, '0') < 0
            ? [['type' => 'negative_amount', 'key' => $key, 'difference' => $grams]]
            : [];
    }

    /**
     * @param  list<array{key: string, grams: string, percent?: string}>  $final
     * @return list<array{type: string, key: string, difference: string}>
     */
    private function percentGaps(array $final, string $totalFinal, string $percentTolerance): array
    {
        $withPercent = collect($final)->filter(fn (array $row): bool => isset($row['percent']));

        if ($withPercent->isEmpty() || ! DecimalMath::isPositive($totalFinal)) {
            return [];
        }

        $gaps = $withPercent
            ->map(function (array $row) use ($totalFinal): array {
                $expected = DecimalMath::percentOf(DecimalMath::of($row['grams']), $totalFinal);

                return ['type' => 'percent_mismatch', 'key' => $row['key'], 'difference' => DecimalMath::sub(DecimalMath::of($row['percent']), $expected)];
            })
            ->reject(fn (array $gap): bool => DecimalMath::withinTolerance($gap['difference'], '0', $percentTolerance))
            ->values()
            ->all();

        $percentTotal = DecimalMath::add(...$withPercent->map(fn (array $row): string => DecimalMath::of($row['percent']))->all());

        if (! DecimalMath::withinTolerance($percentTotal, '100', $percentTolerance)) {
            $gaps[] = ['type' => 'percent_total', 'key' => 'total', 'difference' => DecimalMath::sub($percentTotal, '100')];
        }

        return $gaps;
    }
}
