<?php

namespace App\Enums;

enum ProcurementStage: string
{
    case Quotation = 'quotation';
    case PurchaseOrder = 'purchase_order';
}
