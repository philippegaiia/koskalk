<?php

namespace App;

enum StockReservationStatus: string
{
    case Active = 'active';
    case Released = 'released';
    case Cancelled = 'cancelled';
}
