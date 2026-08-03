<?php

namespace App;

enum GoodsReceiptSource: string
{
    case PurchaseOrder = 'purchase_order';
    case Direct = 'direct';
}
