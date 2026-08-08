<?php

namespace App\Enums;

enum ProductionBenchEntitlementStatus: string
{
    case Active = 'active';
    case Cancelled = 'cancelled';
}
