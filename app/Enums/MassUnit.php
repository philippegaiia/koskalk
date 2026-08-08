<?php

namespace App\Enums;

use InvalidArgumentException;

enum MassUnit: string
{
    case Gram = 'g';
    case Kilogram = 'kg';
    case Ounce = 'oz';
    case Pound = 'lb';

    public function gramsPerUnit(): string
    {
        return match ($this) {
            self::Gram => '1',
            self::Kilogram => '1000',
            self::Ounce => '28.349523125',
            self::Pound => '453.59237',
        };
    }

    public static function fromInput(mixed $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        $unit = is_string($value) ? self::tryFrom($value) : null;

        if (! $unit instanceof self) {
            throw new InvalidArgumentException('Unsupported mass unit.');
        }

        return $unit;
    }
}
