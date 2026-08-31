<?php

namespace App\Services\Inventory;

use App\Services\MassConverter;
use App\Support\NumberLocale;

class InventoryQuantityPresenter
{
    public function __construct(private readonly MassConverter $massConverter) {}

    public function present(
        string $quantity,
        bool $isMass,
        string $displayUnit,
        ?string $locale,
    ): string {
        $displayQuantity = $isMass
            ? $this->massConverter->fromGramsSigned($quantity, $displayUnit)
            : $quantity;
        $decimals = $isMass ? 2 : 0;

        return NumberLocale::formatAdaptiveDecimal(
            $displayQuantity,
            minimumDecimals: $decimals,
            maximumDecimals: $decimals,
            locale: $locale,
        );
    }
}
