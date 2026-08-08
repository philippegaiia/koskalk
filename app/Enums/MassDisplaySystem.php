<?php

namespace App\Enums;

use InvalidArgumentException;

enum MassDisplaySystem: string
{
    case Metric = 'metric';
    case UsCustomary = 'us_customary';

    public function preferredUnit(string|int|float $grams): MassUnit
    {
        $quantity = $this->normalizedQuantity($grams);

        return match ($this) {
            self::Metric => bccomp($quantity, MassUnit::Kilogram->gramsPerUnit(), 9) >= 0
                ? MassUnit::Kilogram
                : MassUnit::Gram,
            self::UsCustomary => bccomp($quantity, MassUnit::Pound->gramsPerUnit(), 9) >= 0
                ? MassUnit::Pound
                : MassUnit::Ounce,
        };
    }

    public function priceUnit(): MassUnit
    {
        return match ($this) {
            self::Metric => MassUnit::Kilogram,
            self::UsCustomary => MassUnit::Pound,
        };
    }

    private function normalizedQuantity(string|int|float $quantity): string
    {
        $normalized = is_float($quantity)
            ? rtrim(rtrim(sprintf('%.18F', $quantity), '0'), '.')
            : trim((string) $quantity);

        if (
            preg_match('/^\d+(?:\.\d+)?$/', $normalized) !== 1
            || bccomp($normalized, '0', 18) < 0
        ) {
            throw new InvalidArgumentException('Mass must be a non-negative decimal quantity.');
        }

        return $normalized;
    }
}
