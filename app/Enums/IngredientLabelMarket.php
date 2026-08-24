<?php

namespace App\Enums;

enum IngredientLabelMarket: string
{
    case Eu = 'eu';
    case Us = 'us';
    case Ca = 'ca';

    public function label(): string
    {
        return match ($this) {
            self::Eu => __('ingredient_admin.market_labels.markets.eu'),
            self::Us => __('ingredient_admin.market_labels.markets.us'),
            self::Ca => __('ingredient_admin.market_labels.markets.ca'),
        };
    }

    /**
     * Market whose provisioned declaration names apply when this market has none.
     * Canada accepts EU names as-is (Health Canada Schedule / INCI basis).
     */
    public function fallbackMarket(): ?IngredientLabelMarket
    {
        return match ($this) {
            self::Ca => self::Eu,
            default => null,
        };
    }
}
