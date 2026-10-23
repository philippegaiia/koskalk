<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Full-precision decimal arithmetic for the DIP calculations: bcmath on canonical decimal strings, never floats.
 * Values are rounded only for display, through round().
 */
final class DecimalMath
{
    public const SCALE = 18;

    /**
     * A canonical decimal string from a database decimal, an integer, or a float produced by a shared calculator.
     * Locale formats are refused: these are machine values, not user input.
     */
    public static function of(mixed $value): string
    {
        if ($value === null) {
            throw new InvalidArgumentException('A calculation value is missing.');
        }

        if (is_float($value)) {
            if (! is_finite($value)) {
                throw new InvalidArgumentException('A calculation value must be finite.');
            }

            $value = rtrim(rtrim(sprintf('%.18F', $value), '0'), '.');
        }

        $string = trim((string) $value);

        if (preg_match('/^-?\d+(\.\d+)?$/', $string) !== 1) {
            throw new InvalidArgumentException("[{$string}] is not a decimal number.");
        }

        return bcadd($string, '0', self::SCALE);
    }

    public static function add(string ...$values): string
    {
        return array_reduce($values, fn (string $sum, string $value): string => bcadd($sum, $value, self::SCALE), '0');
    }

    public static function sub(string $minuend, string $subtrahend): string
    {
        return bcsub($minuend, $subtrahend, self::SCALE);
    }

    public static function mul(string ...$factors): string
    {
        return array_reduce($factors, fn (string $product, string $factor): string => bcmul($product, $factor, self::SCALE), '1');
    }

    public static function div(string $dividend, string $divisor): string
    {
        if (self::isZero($divisor)) {
            throw new InvalidArgumentException('Division by zero in a DIP calculation.');
        }

        return bcdiv($dividend, $divisor, self::SCALE);
    }

    public static function percentOf(string $part, string $whole): string
    {
        return self::div(self::mul($part, '100'), $whole);
    }

    public static function cmp(string $left, string $right): int
    {
        return bccomp($left, $right, self::SCALE);
    }

    public static function isZero(string $value): bool
    {
        return self::cmp($value, '0') === 0;
    }

    public static function isPositive(string $value): bool
    {
        return self::cmp($value, '0') > 0;
    }

    public static function abs(string $value): string
    {
        return self::cmp($value, '0') < 0 ? ltrim($value, '-') : $value;
    }

    public static function withinTolerance(string $left, string $right, string $tolerance): bool
    {
        return self::cmp(self::abs(self::sub($left, $right)), $tolerance) <= 0;
    }

    /**
     * Display rounding, half away from zero. Never feed a rounded value back into a calculation.
     */
    public static function round(string $value, int $places): string
    {
        $half = bcdiv('5', bcpow('10', (string) ($places + 1), 0), $places + 1);
        $rounded = self::cmp($value, '0') < 0
            ? bcsub($value, $half, $places)
            : bcadd($value, $half, $places);

        return self::isZero($rounded) ? bcadd('0', '0', $places) : $rounded;
    }
}
