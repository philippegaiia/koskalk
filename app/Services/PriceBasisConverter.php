<?php

namespace App\Services;

use App\MassUnit;
use InvalidArgumentException;

class PriceBasisConverter
{
    private const int Scale = 9;

    private const int GuardScale = 18;

    public function fromPerKilogram(string|int|float $pricePerKilogram, MassUnit|string $unit): string
    {
        $normalizedPrice = $this->normalizedPrice($pricePerKilogram);
        $targetUnit = MassUnit::fromInput($unit);
        $pricePerGram = bcdiv($normalizedPrice, MassUnit::Kilogram->gramsPerUnit(), self::GuardScale);

        return $this->roundPositive(
            bcmul($pricePerGram, $targetUnit->gramsPerUnit(), self::GuardScale),
        );
    }

    public function toPerKilogram(string|int|float $price, MassUnit|string $unit): string
    {
        $normalizedPrice = $this->normalizedPrice($price);
        $sourceUnit = MassUnit::fromInput($unit);
        $pricePerGram = bcdiv($normalizedPrice, $sourceUnit->gramsPerUnit(), self::GuardScale);

        return $this->roundPositive(
            bcmul($pricePerGram, MassUnit::Kilogram->gramsPerUnit(), self::GuardScale),
        );
    }

    private function normalizedPrice(string|int|float $price): string
    {
        $normalized = is_float($price)
            ? rtrim(rtrim(sprintf('%.18F', $price), '0'), '.')
            : trim((string) $price);

        if (
            preg_match('/^\d+(?:\.\d+)?$/', $normalized) !== 1
            || bccomp($normalized, '0', self::GuardScale) < 0
        ) {
            throw new InvalidArgumentException('Price must be a non-negative decimal amount.');
        }

        return $normalized;
    }

    private function roundPositive(string $price): string
    {
        $roundingIncrement = '0.'.str_repeat('0', self::Scale).'5';
        $adjusted = bcadd($price, $roundingIncrement, self::Scale + 1);

        return bcadd($adjusted, '0', self::Scale);
    }
}
