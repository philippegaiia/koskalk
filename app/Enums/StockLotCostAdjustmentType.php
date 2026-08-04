<?php

namespace App\Enums;

enum StockLotCostAdjustmentType: string
{
    case Shipping = 'shipping';
    case ImportDuty = 'import_duty';
    case NonRecoverableTax = 'non_recoverable_tax';
    case Discount = 'discount';
    case PriceCorrection = 'price_correction';
}
