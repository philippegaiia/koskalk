<?php

namespace App\Services;

use App\MassUnit;
use App\Support\NumberLocale;
use InvalidArgumentException;

class MassConverter
{
    private const int StorageScale = 9;

    private const int GuardScale = 18;

    public function toGrams(string|int|float $quantity, MassUnit|string $unit): string
    {
        return $this->convert($quantity, $unit, MassUnit::Gram);
    }

    public function fromGrams(string|int|float $grams, MassUnit|string $unit): string
    {
        return $this->convert($grams, MassUnit::Gram, $unit);
    }

    public function fromGramsSigned(string|int|float $grams, MassUnit|string $unit): string
    {
        $normalized = is_float($grams)
            ? rtrim(rtrim(sprintf('%.18F', $grams), '0'), '.')
            : trim((string) $grams);
        $isNegative = str_starts_with($normalized, '-');
        $absolute = $isNegative ? substr($normalized, 1) : $normalized;
        $converted = $this->fromGrams($absolute, $unit);

        return $isNegative && bccomp($converted, '0', self::GuardScale) !== 0
            ? '-'.$converted
            : $converted;
    }

    public function convert(
        string|int|float $quantity,
        MassUnit|string $from,
        MassUnit|string $to,
    ): string {
        $normalizedQuantity = $this->normalizedQuantity($quantity);
        $fromUnit = MassUnit::fromInput($from);
        $toUnit = MassUnit::fromInput($to);
        $grams = bcmul($normalizedQuantity, $fromUnit->gramsPerUnit(), self::GuardScale);
        $converted = bcdiv($grams, $toUnit->gramsPerUnit(), self::GuardScale);

        return $this->roundPositive($converted, self::StorageScale);
    }

    private function normalizedQuantity(string|int|float $quantity): string
    {
        $normalized = NumberLocale::normalizeDecimalString($quantity);

        if (
            $normalized === null
            || preg_match('/^\d+(?:\.\d+)?$/', $normalized) !== 1
            || bccomp($normalized, '0', self::GuardScale) < 0
        ) {
            throw new InvalidArgumentException('Mass must be a non-negative decimal quantity.');
        }

        return $normalized;
    }

    private function roundPositive(string $quantity, int $scale): string
    {
        $roundingIncrement = '0.'.str_repeat('0', $scale).'5';
        $adjusted = bcadd($quantity, $roundingIncrement, $scale + 1);

        return bcadd($adjusted, '0', $scale);
    }
}
