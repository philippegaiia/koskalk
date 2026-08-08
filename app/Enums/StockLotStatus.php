<?php

namespace App\Enums;

enum StockLotStatus: string
{
    case Quarantined = 'quarantined';
    case Released = 'released';
}
