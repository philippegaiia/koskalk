<?php

namespace App\Services;

use App\MassUnit;
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
        $normalized = is_float($quantity)
            ? rtrim(rtrim(sprintf('%.18F', $quantity), '0'), '.')
            : trim((string) $quantity);

        if (
            preg_match('/^\d+(?:\.\d+)?$/', $normalized) !== 1
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
