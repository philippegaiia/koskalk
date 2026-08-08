<?php

namespace App\Enums;

enum StockMovementType: string
{
    case OpeningBalance = 'opening_balance';
    case PurchaseReceipt = 'purchase_receipt';
    case ProductionConsumption = 'production_consumption';
    case ProductionOutput = 'production_output';
    case Shipment = 'shipment';
    case Sample = 'sample';
    case Damaged = 'damaged';
    case InternalUse = 'internal_use';
    case StockCountAdjustment = 'stock_count_adjustment';
    case ReceiptReversal = 'receipt_reversal';
    case ProductionCorrection = 'production_correction';
}
