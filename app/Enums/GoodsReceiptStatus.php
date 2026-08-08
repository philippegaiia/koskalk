<?php

namespace App\Enums;

enum GoodsReceiptStatus: string
{
    case Posted = 'posted';
    case Reversed = 'reversed';
}
