<?php

namespace App;

enum ProcurementStage: string
{
    case Quotation = 'quotation';
    case PurchaseOrder = 'purchase_order';
}
