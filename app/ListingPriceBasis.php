<?php

namespace App;

enum ListingPriceBasis: string
{
    case PerUnit = 'per_unit';
    case TotalPurchaseFormat = 'total_purchase_format';
}
