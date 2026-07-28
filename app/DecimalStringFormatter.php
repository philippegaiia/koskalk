<?php

namespace App;

class DecimalStringFormatter
{
    public function toFixed(string $value, int $precision = 2): string
    {
        $normalized = trim($value);

        if ($precision < 0 || preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $normalized, $matches) !== 1) {
            throw new \InvalidArgumentException('The value must be a decimal string.');
        }

        $isNegative = $matches[1] === '-';
        $integer = ltrim($matches[2], '0');
        $integer = $integer === '' ? '0' : $integer;
        $fraction = $matches[3] ?? '';
        $displayFraction = substr(str_pad($fraction, $precision, '0'), 0, $precision);
        $roundingDigit = $fraction[$precision] ?? '0';

        if ($roundingDigit >= '5') {
            $rounded = bcadd(
                $integer.($precision === 0 ? '' : '.'.$displayFraction),
                $precision === 0 ? '1' : '0.'.str_repeat('0', $precision - 1).'1',
                $precision,
            );
            [$integer, $displayFraction] = array_pad(explode('.', $rounded, 2), 2, '');
        }

        $formatted = $integer.($precision === 0 ? '' : '.'.str_pad($displayFraction, $precision, '0'));

        return $isNegative && bccomp($formatted, '0', $precision) !== 0 ? '-'.$formatted : $formatted;
    }
}
