<?php

namespace App\Services;

use InvalidArgumentException;
use LaravelLang\LocaleList\Locale;
use LaravelLang\Locales\Facades\Locales;

class SupportedLocaleCatalog
{
    /**
     * @param  array<int, string>  $excludedCodes
     * @return array<string, string>
     */
    public function options(array $excludedCodes = []): array
    {
        return collect(Locale::cases())
            ->reject(fn (Locale $locale): bool => in_array($locale->value, $excludedCodes, true))
            ->mapWithKeys(function (Locale $locale): array {
                $metadata = $this->metadata($locale->value);

                return [
                    $locale->value => "{$metadata['name']} — {$metadata['native_name']} ({$metadata['code']})",
                ];
            })
            ->sort()
            ->all();
    }

    /**
     * @return array{code: string, name: string, native_name: string, number_locale: string, text_direction: string}
     */
    public function metadata(string $code): array
    {
        if (! Locale::tryFrom($code)) {
            throw new InvalidArgumentException("Unsupported Laravel Lang locale [{$code}].");
        }

        $locale = Locales::info($code);

        return [
            'code' => $locale->code,
            'name' => $locale->localized,
            'native_name' => $locale->native,
            'number_locale' => $locale->regional ?? $locale->code,
            'text_direction' => $locale->direction->value,
        ];
    }
}
