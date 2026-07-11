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
}
