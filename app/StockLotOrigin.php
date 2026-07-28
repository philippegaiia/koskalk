<?php

namespace App;

enum StockLotOrigin: string
{
    case OpeningBalance = 'opening_balance';
    case PurchaseReceipt = 'purchase_receipt';
    case ProductionOutput = 'production_output';
    case Adjustment = 'adjustment';
}
