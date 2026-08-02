<?php

namespace App;

enum GoodsReceiptStatus: string
{
    case Posted = 'posted';
    case Reversed = 'reversed';
}
