<?php

namespace App\Services;

use App\Support\NumberLocale;

class FormulaShareDisplayNumbers
{
    public function technical(mixed $value, ?string $locale, ?string $field): mixed
    {
        return in_array($field, ['koh_sap_value', 'iodine_value', 'ins_value', 'percentage', 'percentage_in_parent', 'concentration_percent', 'peroxide_value', 'max_percentage'], true)
            ? $this->format($value, $locale) : $value;
    }

    public function format(mixed $value, ?string $locale, int $minimumDecimals = 0): mixed
    {
        if (! is_string($value) || preg_match('/^-?\d+\.(\d+)$/', $value, $matches) !== 1) {
            return $value;
        }

        return NumberLocale::formatAdaptiveDecimal($value, $minimumDecimals, max($minimumDecimals, strlen($matches[1])), $locale);
    }
}
