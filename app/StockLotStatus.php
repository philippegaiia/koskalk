<?php

namespace App;

enum StockLotStatus: string
{
    case Quarantined = 'quarantined';
    case Released = 'released';
}
