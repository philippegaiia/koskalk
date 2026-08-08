<?php

namespace App\Enums;

enum GoodsReceiptSource: string
{
    case PurchaseOrder = 'purchase_order';
    case Direct = 'direct';
}
