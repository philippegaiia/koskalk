<?php

namespace App;

enum ProductionBenchEntitlementStatus: string
{
    case Active = 'active';
    case Cancelled = 'cancelled';
}
