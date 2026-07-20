<?php

namespace App\Services;

use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Intl\Currencies;

class CurrencyCatalog
{
    /** @var list<string>|null */
    private ?array $selectableCodes = null;

    /**
     * @return list<string>
     */
    public function selectableCodes(): array
    {
        if ($this->selectableCodes !== null) {
            return $this->selectableCodes;
        }

        $codes = [];

        foreach (array_keys(Currencies::getNames('en')) as $code) {
            try {
                if (Currencies::isValidInAnyCountry($code, legalTender: true, active: true)) {
                    $codes[] = $code;
                }
            } catch (InvalidArgumentException|RuntimeException) {
                continue;
            }
        }

        sort($codes);

        return $this->selectableCodes = $codes;
    }

    /**
     * @param  list<string>  $includeCodes
     * @return array<string, string>
     */
    public function options(?string $locale = null, array $includeCodes = []): array
    {
        $codes = array_values(array_unique([
            ...$this->selectableCodes(),
            ...array_map(fn (string $code): string => strtoupper($code), $includeCodes),
        ]));

        return collect($codes)
            ->mapWithKeys(fn (string $code): array => [
                $code => $this->name($code, $locale),
            ])
            ->sort()
            ->all();
    }

    public function name(string $code, ?string $locale = null): string
    {
        $code = strtoupper($code);

        try {
            return Currencies::getName($code, $locale ?? app()->getLocale());
        } catch (InvalidArgumentException|RuntimeException) {
            return $code;
        }
    }

    public function symbol(string $code, ?string $locale = null): string
    {
        $code = strtoupper($code);

        try {
            return Currencies::getSymbol($code, $locale ?? app()->getLocale());
        } catch (InvalidArgumentException|RuntimeException) {
            return $code;
        }
    }

    public function fractionDigits(string $code): int
    {
        try {
            return Currencies::getFractionDigits(strtoupper($code));
        } catch (InvalidArgumentException|RuntimeException) {
            return 2;
        }
    }

    public function isSelectable(string $code): bool
    {
        return in_array(strtoupper($code), $this->selectableCodes(), true);
    }
}
