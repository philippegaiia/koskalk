<?php

namespace App\Enums;

enum ListingPriceBasis: string
{
    case PerUnit = 'per_unit';
    case TotalPurchaseFormat = 'total_purchase_format';
}
