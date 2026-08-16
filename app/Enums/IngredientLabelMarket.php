<?php

namespace App\Enums;

enum IngredientLabelMarket: string
{
    case Eu = 'eu';
    case Us = 'us';

    public function label(): string
    {
        return match ($this) {
            self::Eu => __('ingredient_admin.market_labels.markets.eu'),
            self::Us => __('ingredient_admin.market_labels.markets.us'),
        };
    }
}
