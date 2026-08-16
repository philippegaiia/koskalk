<?php

namespace App\Support;

use App\DecimalStringFormatter;

class NumberLocale
{
    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_keys(config('number-formats.locales', []));
    }

    public static function isSupported(?string $locale): bool
    {
        return is_string($locale) && in_array($locale, self::codes(), true);
    }

    public static function resolve(?string $locale): string
    {
        if (self::isSupported($locale)) {
            return $locale;
        }

        return config('number-formats.default', 'en_US');
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::codes())
            ->mapWithKeys(fn (string $locale): array => [
                $locale => __("number_formats.options.{$locale}"),
            ])
            ->all();
    }

    public static function formatDecimal(mixed $value, int $decimals = 2, ?string $locale = null): string
    {
        $formatted = number_format((float) $value, $decimals, '.', '');

        return self::usesDecimalComma(self::resolve($locale))
            ? str_replace('.', ',', $formatted)
            : $formatted;
    }

    public static function formatAdaptiveDecimal(
        mixed $value,
        int $minimumDecimals = 2,
        int $maximumDecimals = 4,
        ?string $locale = null,
    ): string {
        if ($minimumDecimals < 0 || $maximumDecimals < $minimumDecimals) {
            throw new \InvalidArgumentException('Decimal limits must be positive and ordered.');
        }

        $normalized = trim((string) $value);

        if (preg_match('/^-?\d+(?:\.\d+)?$/', $normalized) !== 1) {
            if (! is_numeric($normalized)) {
                throw new \InvalidArgumentException('The value must be numeric.');
            }

            $normalized = number_format((float) $normalized, $maximumDecimals, '.', '');
        }

        $fixed = (new DecimalStringFormatter)->toFixed($normalized, $maximumDecimals);
        [$integer, $fraction] = array_pad(explode('.', $fixed, 2), 2, '');

        while (strlen($fraction) > $minimumDecimals && str_ends_with($fraction, '0')) {
            $fraction = substr($fraction, 0, -1);
        }

        $formatted = $integer.($fraction === '' ? '' : '.'.$fraction);

        return self::usesDecimalComma(self::resolve($locale))
            ? str_replace('.', ',', $formatted)
            : $formatted;
    }

    public static function parseDecimalInput(mixed $value): ?float
    {
        $normalized = self::normalizeDecimalString($value);

        return $normalized === null ? null : (float) $normalized;
    }

    public static function normalizeDecimalString(mixed $value): ?string
    {
        $normalized = preg_replace('/[\s\x{00a0}\x{202f}]/u', '', trim((string) $value));

        if ($normalized === null || $normalized === '') {
            return null;
        }

        $commaPosition = strrpos($normalized, ',');
        $dotPosition = strrpos($normalized, '.');

        if ($commaPosition !== false && $dotPosition !== false) {
            $decimalSeparator = $commaPosition > $dotPosition ? ',' : '.';
            $groupingSeparator = $decimalSeparator === ',' ? '.' : ',';
            $normalized = str_replace($groupingSeparator, '', $normalized);
            $normalized = str_replace($decimalSeparator, '.', $normalized);
        } elseif ($commaPosition !== false) {
            $normalized = str_replace(',', '.', $normalized);
        }

        return preg_match('/^-?\d+(?:\.\d+)?$/', $normalized) === 1 ? $normalized : null;
    }

    private static function usesDecimalComma(string $locale): bool
    {
        return in_array($locale, ['fr_FR', 'es_ES', 'de_DE', 'it_IT', 'nl_NL', 'pt_BR'], true);
    }
}
