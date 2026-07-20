<?php

namespace App\Support;

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

    public static function parseDecimalInput(mixed $value): ?float
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

        return is_numeric($normalized) ? (float) $normalized : null;
    }

    private static function usesDecimalComma(string $locale): bool
    {
        return in_array($locale, ['fr_FR', 'es_ES', 'de_DE', 'it_IT', 'nl_NL'], true);
    }
}
